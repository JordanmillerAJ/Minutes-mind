<?php
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// ---------- SQLITE DATABASE INITIALIZATION ----------
$dbFile = __DIR__ . DIRECTORY_SEPARATOR . 'minutemind.sqlite';
try {
    $db = new PDO("sqlite:" . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Create Tables
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE,
            name TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'member',
            created_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS rooms (
            room_code TEXT PRIMARY KEY,
            host_name TEXT NOT NULL,
            host_email TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS signals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_code TEXT NOT NULL,
            sender_tab_id TEXT NOT NULL,
            payload TEXT NOT NULL,
            ts INTEGER NOT NULL
        );
    ");
} catch (Exception $e) {
    http_response_code(500);
    echo "Database Error: " . $e->getMessage();
    exit;
}

// ---------- API ROUTES HANDLER ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $input = file_get_json_input();

    // 1. Room Creation / Access Validation
    if ($_GET['action'] === 'enter_room') {
        $role = $input['role'] ?? 'member';
        $name = trim($input['name'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $roomCode = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', $input['roomCode'] ?? ''));

        if (!$roomCode || !$name) {
            echo json_encode(['error' => 'Room code and user name are required.']);
            exit;
        }

        // Handle DB user persistence
        if ($email) {
            $stmt = $db->prepare("INSERT INTO users (email, name, role, created_at) VALUES (?, ?, ?, ?) ON CONFLICT(email) DO UPDATE SET name=excluded.name");
            $stmt->execute([$email, $name, $role, time()]);
        }

        // Room checks
        $stmt = $db->prepare("SELECT * FROM rooms WHERE room_code = ?");
        $stmt->execute([$roomCode]);
        $room = $stmt->fetch();

        if ($role === 'host') {
            if (!$room) {
                $stmt = $db->prepare("INSERT INTO rooms (room_code, host_name, host_email, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)");
                $stmt->execute([$roomCode, $name, $email, time(), time()]);
            } else {
                // Reactivate room if host rejoins
                $stmt = $db->prepare("UPDATE rooms SET is_active = 1, updated_at = ? WHERE room_code = ?");
                $stmt->execute([time(), $roomCode]);
            }
            echo json_encode(['success' => true, 'roomCode' => $roomCode]);
            exit;
        } else {
            // Member check: Must exist and be active
            if (!$room || (int)$room['is_active'] !== 1) {
                echo json_encode(['error' => 'Room does not exist or has been terminated by the host.']);
                exit;
            }
            echo json_encode(['success' => true, 'roomCode' => $roomCode]);
            exit;
        }
    }

    // 2. Host Terminate Room (Purges Chat & Signal History)
    if ($_GET['action'] === 'terminate_room') {
        $roomCode = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', $input['roomCode'] ?? ''));
        if ($roomCode) {
            // Set inactive
            $stmt = $db->prepare("UPDATE rooms SET is_active = 0 WHERE room_code = ?");
            $stmt->execute([$roomCode]);

            // Clear chat and signaling history in DB for this room
            $stmt = $db->prepare("DELETE FROM signals WHERE room_code = ?");
            $stmt->execute([$roomCode]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // 3. Post Signal/Message
    if ($_GET['action'] === 'send_signal') {
        $roomCode = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', $input['roomCode'] ?? ''));

        if (!$roomCode) {
            echo json_encode(['error' => 'Missing roomCode']);
            exit;
        }

        // Verify active room
        $stmt = $db->prepare("SELECT is_active FROM rooms WHERE room_code = ?");
        $stmt->execute([$roomCode]);
        $room = $stmt->fetch();

        if (!$room || (int)$room['is_active'] !== 1) {
            echo json_encode(['error' => 'Room inactive']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO signals (room_code, sender_tab_id, payload, ts) VALUES (?, ?, ?, ?)");
        $stmt->execute([$roomCode, $input['from'] ?? '', json_encode($input), $input['ts'] ?? (int)(microtime(true)*1000)]);

        echo json_encode(['success' => true]);
        exit;
    }

    // 4. Poll Signals
    if ($_GET['action'] === 'poll_signals') {
        $roomCode = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', $input['roomCode'] ?? ''));
        $since = (int)($input['since'] ?? 0);

        // Check active status
        $stmt = $db->prepare("SELECT is_active FROM rooms WHERE room_code = ?");
        $stmt->execute([$roomCode]);
        $room = $stmt->fetch();

        if (!$room || (int)$room['is_active'] !== 1) {
            echo json_encode(['room_terminated' => true, 'messages' => []]);
            exit;
        }

        $stmt = $db->prepare("SELECT payload FROM signals WHERE room_code = ? AND ts > ? ORDER BY ts ASC");
        $stmt->execute([$roomCode, $since]);
        $rows = $stmt->fetchAll();

        $messages = array_map(fn($r) => json_decode($r['payload'], true), $rows);
        echo json_encode(['room_terminated' => false, 'messages' => $messages]);
        exit;
    }

    // 5. Ollama Health Check
    if ($_GET['action'] === 'ping') {
        $ollamaHost = getenv('OLLAMA_HOST_URL') ?: 'http://127.0.0.1:11434';
        $ch = curl_init($ollamaHost . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo json_encode(['ollama' => ($res !== false && $code === 200)]);
        exit;
    }

    // 6. AI Minutes Generation
    if ($_GET['action'] === 'generate') {
        header('Cache-Control: no-store');
        $transcript = $input['transcript'] ?? '';
        $customPrompt = $input['custom_prompt'] ?? '';

        if (empty(trim($transcript))) {
            echo json_encode(['error' => 'Transcript content cannot be empty.']);
            exit;
        }

        $system_prompt = "You are an expert executive assistant. "
            . "Analyze meeting transcripts and generate structured Meeting Minutes in clean Markdown format. "
            . "Include Executive Summary, Key Decisions, and Action Items.";

        if (!empty($customPrompt)) {
            $system_prompt .= " " . $customPrompt;
        }

        $payload = [
            'model'  => 'qwen2.5-coder:7b',
            'system' => $system_prompt,
            'prompt' => $transcript,
            'stream' => false
        ];

        $ollamaHost = getenv('OLLAMA_HOST_URL') ?: 'http://127.0.0.1:11434';
        $ch = curl_init($ollamaHost . '/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
        ]);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_error) {
            echo json_encode(['error' => 'cURL Error: ' . $curl_error]);
            exit;
        }

        $data = json_decode($response, true);
        if (isset($data['response'])) {
            echo json_encode(['success' => true, 'minutes' => $data['response']]);
        } else {
            echo json_encode(['error' => 'LLM extraction failed: ' . $response]);
        }
        exit;
    }
}

function file_get_json_input() {
    $content = trim(file_get_contents("php://input"));
    return json_decode($content, true) ?? [];
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MinuteMind Pro — Network Workspace</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>

    <style>
        :root {
            --bg-main: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --primary: #0284c7;
            --primary-hover: #0369a1;
        }

        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background-color: var(--bg-main);
            color: var(--text-primary);
        }

        .light-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05), 0 1px 2px 0 rgba(0, 0, 0, 0.03);
        }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            font-weight: 600; font-size: .8125rem; border-radius: 6px; padding: .5rem 1rem;
            transition: all .15s ease; cursor: pointer;
        }
        .btn-primary {
            background-color: var(--primary); color: #ffffff;
        }
        .btn-primary:hover { background-color: var(--primary-hover); }

        .btn-ghost {
            background-color: #f1f5f9; color: var(--text-secondary); border: 1px solid #cbd5e1;
        }
        .btn-ghost:hover { background-color: #e2e8f0; color: var(--text-primary); }

        .btn-danger {
            background-color: #ef4444; color: #ffffff;
        }
        .btn-danger:hover { background-color: #dc2626; }

        .field {
            width: 100%;
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 6px; padding: .5rem .75rem; color: var(--text-primary);
            font-size: .875rem; transition: border-color .15s ease;
        }
        .field:focus { outline: none; border-color: var(--primary); background-color: #ffffff; }

        .pill {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .2rem .6rem; border-radius: 9999px;
            font-size: .7rem; font-weight: 600;
        }
        .pill-live { background-color: #dcfce7; color: #166534; }
        .pill-off { background-color: #f1f5f9; color: #64748b; }
        .pill .dot { width: .4rem; height: .4rem; border-radius: 9999px; background-color: currentColor; }

        #toastWrap { position: fixed; top: 1rem; right: 1rem; z-index: 200; display: flex; flex-direction: column; gap: .5rem; }
        .toast {
            background: #ffffff; border: 1px solid #e2e8f0; border-left: 4px solid var(--primary);
            border-radius: 6px; padding: .75rem 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            opacity: 0; transform: translateX(20px); transition: all .2s ease;
        }
        .toast.show { opacity: 1; transform: translateX(0); }

        .chat-bubble { padding: .5rem .75rem; border-radius: 8px; font-size: .8125rem; max-width: 85%; word-break: break-word; }
        .chat-mine { background-color: #e0f2fe; color: #0369a1; align-self: flex-end; }
        .chat-other { background-color: #f1f5f9; color: #334155; align-self: flex-start; }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <div id="toastWrap"></div>

    <!-- ================= HEADER ================= -->
    <header id="appHeader" class="hidden border-b border-slate-200 bg-white sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <i class="fa-solid fa-users-viewfinder text-sky-600 text-xl"></i>
                <div class="flex items-center gap-2">
                    <h1 class="text-base font-bold text-slate-800">MinuteMind Pro</h1>
                    <span id="roleBadge" class="px-2 py-0.5 text-[11px] font-semibold rounded bg-sky-100 text-sky-700">HOST</span>
                    <span id="roomBadge" class="px-2 py-0.5 text-[11px] font-semibold rounded bg-slate-100 text-slate-700">ROOM: ---</span>
                </div>
            </div>

            <div class="flex items-center space-x-3">
                <span id="ollamaPill" class="pill pill-off"><span class="dot"></span><span id="ollamaText">Ollama Off</span></span>
                <div class="flex items-center gap-2 bg-slate-50 px-2 py-1 rounded border border-slate-200">
                    <div id="userAvatar" class="w-6 h-6 rounded-full bg-sky-600 flex items-center justify-center font-bold text-xs text-white">U</div>
                    <span id="userNameDisplay" class="text-xs font-medium text-slate-700">User</span>
                </div>
                <button id="terminateBtn" title="Terminate Room (Host)" class="btn btn-danger py-1 text-xs hidden"><i class="fa-solid fa-power-off mr-1"></i> Terminate</button>
                <button id="logoutBtn" title="Leave Room" class="btn btn-ghost py-1 text-xs"><i class="fa-solid fa-right-from-bracket"></i> Leave</button>
            </div>
        </div>
    </header>

    <!-- ================= AUTH & ROOM ENTRY SCREEN ================= -->
    <div id="authScreen" class="flex-1 flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-md light-card p-8">
            <div class="text-center mb-6">
                <div class="w-12 h-12 rounded-full bg-sky-50 text-sky-600 flex items-center justify-center mx-auto mb-3">
                    <i class="fa-solid fa-network-wired text-xl"></i>
                </div>
                <h2 class="text-xl font-bold text-slate-800">MinuteMind Workspace</h2>
                <p class="text-xs text-slate-500 mt-1">Lightweight LAN Multi-User Collaboration</p>
            </div>

            <div class="flex rounded-lg bg-slate-100 p-1 mb-6 border border-slate-200">
                <button id="tabHostBtn" type="button" class="flex-1 py-1.5 text-xs font-semibold rounded-md bg-white text-slate-800 shadow-sm">Host Session</button>
                <button id="tabMemberBtn" type="button" class="flex-1 py-1.5 text-xs font-semibold rounded-md text-slate-600 hover:text-slate-800">Join Member</button>
            </div>

            <!-- HOST FORM -->
            <form id="hostAuthForm" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Host Name</label>
                    <input type="text" id="hostName" required class="field" placeholder="e.g. Alex Host">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Email Address</label>
                    <input type="email" id="hostEmail" required class="field" placeholder="alex@company.com">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Room Code</label>
                    <div class="flex gap-2">
                        <input type="text" id="hostRoomCode" required class="field uppercase" placeholder="e.g. ROOM1234">
                        <button type="button" id="genRoomBtn" class="btn btn-ghost shrink-0"><i class="fa-solid fa-dice"></i></button>
                    </div>
                </div>
                <button type="submit" class="w-full btn btn-primary py-2.5 text-xs">Create & Host Room</button>
            </form>

            <!-- MEMBER FORM -->
            <form id="memberAuthForm" class="space-y-4 hidden">
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Your Name</label>
                    <input type="text" id="memberName" required class="field" placeholder="e.g. Sarah Member">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Email Address (Optional)</label>
                    <input type="email" id="memberEmail" class="field" placeholder="sarah@company.com">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Target Room Code</label>
                    <input type="text" id="memberRoomCode" required class="field uppercase" placeholder="Enter Host Code">
                </div>
                <button type="submit" class="w-full btn btn-primary py-2.5 text-xs">Join Room Session</button>
            </form>
        </div>
    </div>

    <!-- ================= MAIN WORKSPACE ================= -->
    <main id="appWorkspace" class="hidden flex-1 max-w-7xl w-full mx-auto px-4 py-6">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- LEFT/MAIN COLUMN -->
            <div class="lg:col-span-8 space-y-6">

                <!-- HOST BOARD -->
                <div id="hostBoard" class="hidden space-y-6">
                    <div class="light-card p-4">
                        <div class="flex justify-between items-center mb-3">
                            <span class="text-xs font-bold text-slate-700 uppercase"><i class="fa-solid fa-video text-sky-600 mr-1"></i> Host WebRTC Stream</span>
                            <span class="text-[11px] text-slate-400">P2P Broadcast</span>
                        </div>
                        <div class="relative bg-slate-900 aspect-video rounded-lg flex items-center justify-center overflow-hidden">
                            <video id="hostVideoPlayer" class="w-full h-full object-cover hidden" autoplay playsinline muted></video>
                            <div id="videoPlaceholder" class="text-center text-slate-400">
                                <i class="fa-solid fa-camera text-3xl mb-2"></i>
                                <p class="text-xs">No active video stream</p>
                            </div>
                        </div>
                        <div class="flex gap-2 mt-3">
                            <button id="startCamBtn" class="btn btn-ghost text-xs"><i class="fa-solid fa-webcam"></i> Camera</button>
                            <label class="btn btn-ghost text-xs cursor-pointer">
                                <i class="fa-solid fa-folder-open"></i> Video File
                                <input type="file" id="videoFileInput" accept="video/*" class="hidden">
                            </label>
                            <button id="stopVideoBtn" class="btn btn-danger text-xs hidden"><i class="fa-solid fa-power-off"></i> Stop Stream</button>
                        </div>
                    </div>

                    <div class="light-card p-4 space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-bold text-slate-700 uppercase"><i class="fa-solid fa-wand-magic-sparkles text-sky-600 mr-1"></i> Meeting Notes Input</span>
                            <button id="loadSampleBtn" class="text-[11px] text-sky-600 bg-sky-50 px-2 py-0.5 rounded border border-sky-200">Sample Text</button>
                        </div>
                        <textarea id="transcriptInput" rows="4" class="field resize-none" placeholder="Paste transcript or notes here..."></textarea>
                        <button id="generateBtn" class="w-full btn btn-primary text-xs py-2"><i class="fa-solid fa-bolt"></i> Generate AI Minutes</button>
                    </div>

                    <div class="light-card p-4 space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-bold text-slate-700 uppercase"><i class="fa-solid fa-file-lines text-sky-600 mr-1"></i> Formatted Minutes</span>
                            <button id="broadcastBtn" class="btn btn-primary text-[11px]"><i class="fa-solid fa-share-nodes"></i> Share with Members</button>
                        </div>
                        <div id="formattedOutput" class="bg-slate-50 p-4 rounded-lg text-xs text-slate-700 border border-slate-200 min-h-[120px] prose max-w-none">
                            <em class="text-slate-400">Generated minutes will render here...</em>
                        </div>
                    </div>
                </div>

                <!-- MEMBER BOARD -->
                <div id="memberBoard" class="hidden space-y-6">
                    <div class="light-card p-4">
                        <div class="flex justify-between items-center mb-3">
                            <span class="text-xs font-bold text-slate-700 uppercase"><i class="fa-solid fa-display text-sky-600 mr-1"></i> Host Stream</span>
                            <span id="hostConnPill" class="pill pill-off"><span class="dot"></span><span id="hostConnText">Offline</span></span>
                        </div>
                        <div class="relative bg-slate-900 aspect-video rounded-lg flex items-center justify-center overflow-hidden">
                            <video id="memberVideoPlayer" class="w-full h-full object-cover hidden" autoplay playsinline controls muted></video>
                            <div id="memberVideoPlaceholder" class="text-center p-4 text-slate-400">
                                <i class="fa-solid fa-tower-broadcast text-3xl mb-2"></i>
                                <p class="text-xs">Awaiting host video broadcast...</p>
                            </div>
                        </div>
                    </div>

                    <div class="light-card p-4">
                        <h3 class="text-xs font-bold text-slate-700 uppercase mb-3"><i class="fa-solid fa-newspaper text-sky-600 mr-1"></i> Published Meeting Minutes</h3>
                        <div id="memberFormattedOutput" class="bg-slate-50 p-4 rounded-lg text-xs text-slate-700 border border-slate-200 min-h-[150px] prose max-w-none">
                            <em class="text-slate-400">Awaiting host published notes...</em>
                        </div>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN -->
            <div class="lg:col-span-4 space-y-6">
                <div class="light-card p-4">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-xs font-bold text-slate-700 uppercase"><i class="fa-solid fa-users text-sky-600 mr-1"></i> Active Members</span>
                        <span id="peerCountBadge" class="text-[11px] font-bold px-2 py-0.5 rounded bg-sky-50 text-sky-700 border border-sky-100">1</span>
                    </div>
                    <ul id="peerList" class="space-y-1 text-xs max-h-32 overflow-y-auto"></ul>
                </div>

                <!-- Chat System -->
                <div class="light-card p-4 flex flex-col h-[480px]">
                    <div class="flex rounded-lg bg-slate-100 p-1 mb-3 border border-slate-200 shrink-0">
                        <button id="tabPublicChat" class="flex-1 py-1 text-[11px] font-semibold rounded-md bg-white text-slate-800 shadow-sm">Public Chat</button>
                        <button id="tabPrivateChat" class="flex-1 py-1 text-[11px] font-semibold rounded-md text-slate-600 hover:text-slate-800">Private Direct</button>
                    </div>

                    <div id="privateTargetWrap" class="hidden mb-2 shrink-0">
                        <select id="privateTargetSelect" class="field text-xs py-1"></select>
                    </div>

                    <div id="chatBox" class="flex-1 bg-slate-50 rounded-lg border border-slate-200 p-3 overflow-y-auto flex flex-col gap-2 mb-3"></div>

                    <form id="chatForm" class="flex gap-2 shrink-0">
                        <input type="text" id="chatInput" class="field text-xs" placeholder="Type message..." required>
                        <button type="submit" class="btn btn-primary px-3"><i class="fa-solid fa-paper-plane"></i></button>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script>
    const MY = {
        tabId: (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'id-' + Date.now() + '-' + Math.random().toString(16).slice(2),
        role: null, name: null, email: null, roomCode: null
    };

    let lastPolledTimestamp = 0;
    let currentMarkdownResult = "";
    const peers = new Map();
    let activeChatTab = 'public';

    const rtcConnections = new Map();
    let localStream = null;

    const rtcConfig = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };
    const $ = (id) => document.getElementById(id);

    async function sendToNetwork(payload) {
        if (!MY.roomCode) return;
        const msg = Object.assign({
            from: MY.tabId,
            roomCode: MY.roomCode,
            senderName: MY.name,
            senderRole: MY.role,
            ts: Date.now()
        }, payload);

        try {
            await fetch('index.php?action=send_signal', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(msg)
            });
        } catch (e) {
            console.error('Signal dispatch error:', e);
        }
    }

    async function pollNetworkSignals() {
        if (!MY.roomCode) return;

        try {
            const res = await fetch('index.php?action=poll_signals', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ roomCode: MY.roomCode, since: lastPolledTimestamp })
            });
            const data = await res.json();

            if (data.room_terminated) {
                toast('Room Closed', 'Host terminated this room session.');
                setTimeout(() => location.reload(), 1500);
                return;
            }

            if (data.messages && data.messages.length > 0) {
                for (const m of data.messages) {
                    if (m.ts > lastPolledTimestamp) {
                        lastPolledTimestamp = m.ts;
                    }
                    if (m.from !== MY.tabId) {
                        handleIncomingSignal(m);
                    }
                }
            }
        } catch (e) {
            console.error('Poll error:', e);
        }
    }

    async function checkOllamaStatus() {
        try {
            const res = await fetch('index.php?action=ping', { method: 'POST' });
            const data = await res.json();
            if (data.ollama) {
                $('ollamaPill').className = 'pill pill-live';
                $('ollamaText').textContent = 'Ollama Online';
            } else {
                $('ollamaPill').className = 'pill pill-off';
                $('ollamaText').textContent = 'Ollama Off';
            }
        } catch (e) {
            $('ollamaPill').className = 'pill pill-off';
            $('ollamaText').textContent = 'Ollama Off';
        }
    }

    function toast(title, msg) {
        const wrap = $('toastWrap');
        const el = document.createElement('div');
        el.className = 'toast';
        el.innerHTML = `<div><p class="font-bold text-xs text-slate-800">${title}</p><p class="text-[11px] text-slate-600">${msg || ''}</p></div>`;
        wrap.appendChild(el);
        requestAnimationFrame(() => el.classList.add('show'));
        setTimeout(() => { el.remove(); }, 3500);
    }

    $('tabHostBtn').addEventListener('click', () => {
        $('tabHostBtn').className = 'flex-1 py-1.5 text-xs font-semibold rounded-md bg-white text-slate-800 shadow-sm';$('tabMemberBtn').className = 'flex-1 py-1.5 text-xs font-semibold rounded-md text-slate-600 hover:text-slate-800';
        $('hostAuthForm').classList.remove('hidden');$('memberAuthForm').classList.add('hidden');
    });

    $('tabMemberBtn').addEventListener('click', () => {
        $('tabMemberBtn').className = 'flex-1 py-1.5 text-xs font-semibold rounded-md bg-white text-slate-800 shadow-sm';$('tabHostBtn').className = 'flex-1 py-1.5 text-xs font-semibold rounded-md text-slate-600 hover:text-slate-800';
        $('memberAuthForm').classList.remove('hidden');$('hostAuthForm').classList.add('hidden');
    });

    $('genRoomBtn').addEventListener('click', () => {$('hostRoomCode').value = 'ROOM' + Math.floor(1000 + Math.random() * 9000);
    });

    async function enterRoomRequest(user) {
        try {
            const res = await fetch('index.php?action=enter_room', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(user)
            });
            const data = await res.json();
            if (data.error) throw new Error(data.error);

            MY.role = user.role;
            MY.name = user.name;
            MY.email = user.email || '';
            MY.roomCode = data.roomCode;

            $('userNameDisplay').textContent = MY.name;
            $('userAvatar').textContent = MY.name.charAt(0).toUpperCase();
            $('roleBadge').textContent = MY.role.toUpperCase();$('roomBadge').textContent = 'ROOM: ' + MY.roomCode;

            $('authScreen').classList.add('hidden');
            $('appHeader').classList.remove('hidden');$('appWorkspace').classList.remove('hidden');

            if (MY.role === 'host') {
                $('hostBoard').classList.remove('hidden');
                $('memberBoard').classList.add('hidden');$('terminateBtn').classList.remove('hidden');
            } else {
                $('memberBoard').classList.remove('hidden');
                $('hostBoard').classList.add('hidden');$('terminateBtn').classList.add('hidden');
            }

            sendToNetwork({ type: 'presence' });
            setInterval(pollNetworkSignals, 1500);
            setInterval(sendPresence, 4000);

            checkOllamaStatus();
            setInterval(checkOllamaStatus, 10000);

            toast('Joined Room', `Active in room ${MY.roomCode}`);
            renderPeerList();
        } catch (e) {
            toast('Access Denied', e.message);
        }
    }

    function sendPresence() {
        if (MY.roomCode) {
            sendToNetwork({ type: 'presence' });
            prunePeers();
        }
    }

    $('hostAuthForm').addEventListener('submit', (e) => {
        e.preventDefault();
        enterRoomRequest({
            role: 'host',
            name: $('hostName').value,
            email: $('hostEmail').value,
            roomCode: $('hostRoomCode').value
        });
    });

    $('memberAuthForm').addEventListener('submit', (e) => {
        e.preventDefault();
        enterRoomRequest({
            role: 'member',
            name: $('memberName').value,
            email: $('memberEmail').value,
            roomCode: $('memberRoomCode').value
        });
    });

    $('terminateBtn').addEventListener('click', async () => {
        if (!confirm('Are you sure you want to terminate this room? Chat and signaling state will be wiped.')) return;
        await fetch('index.php?action=terminate_room', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ roomCode: MY.roomCode })
        });
        location.reload();
    });

    $('logoutBtn').addEventListener('click', () => {
        stopBroadcast();
        sendToNetwork({ type: 'bye' });
        location.reload();
    });

    function prunePeers() {
        const now = Date.now();
        peers.forEach((p, id) => {
            if (now - p.lastSeen > 12000) {
                closePeerConnection(id);
                peers.delete(id);
            }
        });
        renderPeerList();
    }

    function renderPeerList() {
        const list = $('peerList');
        const select = $('privateTargetSelect');
        list.innerHTML = '';
        select.innerHTML = '';

        const selfItem = document.createElement('li');
        selfItem.className = 'text-sky-600 font-semibold';
        selfItem.textContent = `${MY.name} (${MY.role.toUpperCase()}) - You`;
        list.appendChild(selfItem);

        let count = 1;
        peers.forEach((p, id) => {
            count++;
            const item = document.createElement('li');
            item.className = 'text-slate-600';
            item.textContent = `${p.name} (${p.role.toUpperCase()})`;
            list.appendChild(item);

            const opt = document.createElement('option');
            opt.value = id;
            opt.textContent = `${p.name} (${p.role.toUpperCase()})`;
            select.appendChild(opt);
        });

        $('peerCountBadge').textContent = count;
    }

    $('tabPublicChat').addEventListener('click', () => {
        activeChatTab = 'public';
        $('tabPublicChat').className = 'flex-1 py-1 text-[11px] font-semibold rounded-md bg-white text-slate-800 shadow-sm';
        $('tabPrivateChat').className = 'flex-1 py-1 text-[11px] font-semibold rounded-md text-slate-600 hover:text-slate-800';$('privateTargetWrap').classList.add('hidden');
    });

    $('tabPrivateChat').addEventListener('click', () => {
        activeChatTab = 'private';
        $('tabPrivateChat').className = 'flex-1 py-1 text-[11px] font-semibold rounded-md bg-white text-slate-800 shadow-sm';
        $('tabPublicChat').className = 'flex-1 py-1 text-[11px] font-semibold rounded-md text-slate-600 hover:text-slate-800';$('privateTargetWrap').classList.remove('hidden');
    });

    $('chatForm').addEventListener('submit', (e) => {
        e.preventDefault();
        const text = $('chatInput').value.trim();
        if (!text) return;

        if (activeChatTab === 'public') {
            sendToNetwork({ type: 'public-chat', text: text });
            appendChatMessage({ senderName: MY.name, text: text, isMine: true, isPrivate: false });
        } else {
            const targetId = $('privateTargetSelect').value;
            if (!targetId) { toast('Error', 'Select a room member.'); return; }
            sendToNetwork({ type: 'private-chat', targetTabId: targetId, text: text });
            appendChatMessage({ senderName: `To ${peers.get(targetId)?.name || 'User'}`, text: text, isMine: true, isPrivate: true });
        }
        $('chatInput').value = '';
    });

    function appendChatMessage(msg) {
        const box = $('chatBox');
        const el = document.createElement('div');
        el.className = `chat-bubble ${msg.isMine ? 'chat-mine' : 'chat-other'}`;

        const sender = document.createElement('div');
        sender.className = 'font-bold text-[10px] opacity-75 mb-0.5';
        sender.textContent = `${msg.senderName} ${msg.isPrivate ? '(Private Direct)' : ''}`;

        const content = document.createElement('div');
        content.textContent = msg.text;

        el.appendChild(sender);
        el.appendChild(content);
        box.appendChild(el);
        box.scrollTop = box.scrollHeight;
    }

    async function startCamera() {
        try {
            localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
            attachHostStream(localStream);
        } catch (err) {
            toast('Camera Error', err.message || 'Could not access camera/microphone');
        }
    }

    function handleVideoFileSelect(e) {
        const file = e.target.files[0];
        if (!file) return;

        const videoEl = document.createElement('video');
        videoEl.src = URL.createObjectURL(file);
        videoEl.loop = true;
        videoEl.muted = true;
        videoEl.play();

        videoEl.onplay = () => {
            localStream = videoEl.captureStream ? videoEl.captureStream() : videoEl.mozCaptureStream();
            attachHostStream(localStream);
        };
    }

    function attachHostStream(stream) {
        const hostVideo = $('hostVideoPlayer');
        hostVideo.srcObject = stream;
        hostVideo.classList.remove('hidden');
        $('videoPlaceholder').classList.add('hidden');$('stopVideoBtn').classList.remove('hidden');

        peers.forEach((p, memberId) => {
            if (p.role === 'member') initiatePeerConnection(memberId);
        });

        sendToNetwork({ type: 'stream-started' });
        toast('Broadcasting', 'Live video active.');
    }

    function stopBroadcast() {
        if (localStream) {
            localStream.getTracks().forEach(track => track.stop());
            localStream = null;
        }

        rtcConnections.forEach((pc) => pc.close());
        rtcConnections.clear();

        const hostVideo = $('hostVideoPlayer');
        hostVideo.srcObject = null;
        hostVideo.classList.add('hidden');
        $('videoPlaceholder').classList.remove('hidden');$('stopVideoBtn').classList.add('hidden');

        sendToNetwork({ type: 'stream-stopped' });
    }

    function createPeerConnection(targetTabId) {
        if (rtcConnections.has(targetTabId)) {
            rtcConnections.get(targetTabId).close();
        }

        const pc = new RTCPeerConnection(rtcConfig);
        rtcConnections.set(targetTabId, pc);

        pc.onicecandidate = (event) => {
            if (event.candidate) {
                sendToNetwork({
                    type: 'rtc-candidate',
                    targetTabId: targetTabId,
                    candidate: event.candidate
                });
            }
        };

        if (MY.role === 'host' && localStream) {
            localStream.getTracks().forEach(track => pc.addTrack(track, localStream));
        }

        if (MY.role === 'member') {
            pc.ontrack = (event) => {
                const memberVideo = $('memberVideoPlayer');
                memberVideo.srcObject = event.streams[0];
                memberVideo.classList.remove('hidden');
                $('memberVideoPlaceholder').classList.add('hidden');

                $('hostConnPill').className = 'pill pill-live';$('hostConnText').textContent = 'Live Feed';
            };

            pc.oniceconnectionstatechange = () => {
                if (['disconnected', 'failed', 'closed'].includes(pc.iceConnectionState)) {
                    $('hostConnPill').className = 'pill pill-off';$('hostConnText').textContent = 'Offline';
                }
            };
        }

        return pc;
    }

    async function initiatePeerConnection(memberTabId) {
        const pc = createPeerConnection(memberTabId);
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);

        sendToNetwork({
            type: 'rtc-offer',
            targetTabId: memberTabId,
            offer: offer
        });
    }

    function closePeerConnection(tabId) {
        if (rtcConnections.has(tabId)) {
            rtcConnections.get(tabId).close();
            rtcConnections.delete(tabId);
        }
    }

    $('startCamBtn').addEventListener('click', startCamera);
    $('videoFileInput').addEventListener('change', handleVideoFileSelect);$('stopVideoBtn').addEventListener('click', stopBroadcast);

    async function handleIncomingSignal(m) {
        switch (m.type) {
            case 'presence':
                peers.set(m.from, { name: m.senderName, role: m.senderRole, lastSeen: Date.now() });
                renderPeerList();

                if (MY.role === 'host' && localStream && m.senderRole === 'member' && !rtcConnections.has(m.from)) {
                    initiatePeerConnection(m.from);
                }
                break;

            case 'bye':
                closePeerConnection(m.from);
                peers.delete(m.from);
                renderPeerList();
                break;

            case 'public-chat':
                appendChatMessage({ senderName: m.senderName, text: m.text, isMine: false, isPrivate: false });
                break;

            case 'private-chat':
                if (m.targetTabId === MY.tabId) {
                    appendChatMessage({ senderName: m.senderName, text: m.text, isMine: false, isPrivate: true });
                    toast('Private Message', `New message from ${m.senderName}`);
                }
                break;

            case 'published-minutes':
                if (MY.role === 'member') {
                    $('memberFormattedOutput').innerHTML = DOMPurify.sanitize(marked.parse(m.markdown));
                    toast('Minutes Received', `Host published meeting minutes.`);
                }
                break;

            case 'stream-started':
                if (MY.role === 'member') toast('Host Streaming', `${m.senderName} started video stream.`);
                break;

            case 'stream-stopped':
                if (MY.role === 'member') {
                    const memberVideo = $('memberVideoPlayer');
                    memberVideo.srcObject = null;
                    memberVideo.classList.add('hidden');
                    $('memberVideoPlaceholder').classList.remove('hidden');
                    $('hostConnPill').className = 'pill pill-off';$('hostConnText').textContent = 'Offline';
                }
                break;

            case 'rtc-offer':
                if (m.targetTabId === MY.tabId) {
                    const pc = createPeerConnection(m.from);
                    await pc.setRemoteDescription(new RTCSessionDescription(m.offer));
                    const answer = await pc.createAnswer();
                    await pc.setLocalDescription(answer);

                    sendToNetwork({
                        type: 'rtc-answer',
                        targetTabId: m.from,
                        answer: answer
                    });
                }
                break;

            case 'rtc-answer':
                if (m.targetTabId === MY.tabId) {
                    const pc = rtcConnections.get(m.from);
                    if (pc) await pc.setRemoteDescription(new RTCSessionDescription(m.answer));
                }
                break;

            case 'rtc-candidate':
                if (m.targetTabId === MY.tabId) {
                    const pc = rtcConnections.get(m.from);
                    if (pc && m.candidate) await pc.addIceCandidate(new RTCIceCandidate(m.candidate));
                }
                break;
        }
    }

    $('loadSampleBtn')?.addEventListener('click', () => {$('transcriptInput').value = "John: We need to finalize the cloud provider today.\nSarah: GCP offers better K8s support, but AWS has lower database latency for our region.\nJohn: Let's proceed with AWS and setup strict budget alerts.\nSarah: Agreed, I will draft implementation docs by Friday.";
    });

    $('generateBtn')?.addEventListener('click', async () => {
        const transcript = $('transcriptInput').value;
        if (!transcript.trim()) return toast('Error', 'Transcript cannot be empty');

        try {
            const res = await fetch('index.php?action=generate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ transcript })
            });
            const data = await res.json();
            if (data.error) throw new Error(data.error);

            currentMarkdownResult = data.minutes;
            $('formattedOutput').innerHTML = DOMPurify.sanitize(marked.parse(data.minutes));
            toast('Complete', 'AI Minutes generated.');
        } catch (err) {
            toast('AI Error', err.message);
        }
    });

    $('broadcastBtn')?.addEventListener('click', () => {
        if (!currentMarkdownResult) return toast('Warning', 'Generate minutes first');
        sendToNetwork({ type: 'published-minutes', markdown: currentMarkdownResult });
        toast('Published', 'Sent to all active room members.');
    });

    window.addEventListener('beforeunload', () => {
        stopBroadcast();
        sendToNetwork({ type: 'bye' });
    });
    </script>
</body>
</html>
