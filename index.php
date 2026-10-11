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

        if ($email) {
            $stmt = $db->prepare("INSERT INTO users (email, name, role, created_at) VALUES (?, ?, ?, ?) ON CONFLICT(email) DO UPDATE SET name=excluded.name");
            $stmt->execute([$email, $name, $role, time()]);
        }

        $stmt = $db->prepare("SELECT * FROM rooms WHERE room_code = ?");
        $stmt->execute([$roomCode]);
        $room = $stmt->fetch();

        if ($role === 'host') {
            if (!$room) {
                $stmt = $db->prepare("INSERT INTO rooms (room_code, host_name, host_email, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)");
                $stmt->execute([$roomCode, $name, $email, time(), time()]);
            } else {
                $stmt = $db->prepare("UPDATE rooms SET is_active = 1, updated_at = ? WHERE room_code = ?");
                $stmt->execute([time(), $roomCode]);
            }
            echo json_encode(['success' => true, 'roomCode' => $roomCode]);
            exit;
        } else {
            if (!$room || (int)$room['is_active'] !== 1) {
                echo json_encode(['error' => 'Room does not exist or has been terminated by the host.']);
                exit;
            }
            echo json_encode(['success' => true, 'roomCode' => $roomCode]);
            exit;
        }
    }

    // 2. Host Terminate Room
    if ($_GET['action'] === 'terminate_room') {
        $roomCode = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', $input['roomCode'] ?? ''));
        if ($roomCode) {
            $stmt = $db->prepare("UPDATE rooms SET is_active = 0 WHERE room_code = ?");
            $stmt->execute([$roomCode]);
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

        $stmt = $db->prepare("SELECT is_active FROM rooms WHERE room_code = ?");
        $stmt->execute([$roomCode]);
        $room = $stmt->fetch();

        if (!$room || (int)$room['is_active'] !== 1) {
            echo json_encode(['room_terminated' => true, 'messages' => []]);
            exit;
        }

        $stmt = $db->prepare("SELECT payload FROM signals WHERE room_code = ? AND ts > ? ORDER BY id ASC");
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
    <title>MinuteMind Pro — Modern Workspace</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>

    <style>
        :root {
            --bg-main: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.7);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --accent-glow: rgba(14, 165, 233, 0.15);
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: radial-gradient(circle at top, #1e293b 0%, #0f172a 100%);
            color: var(--text-primary);
        }

        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .glass-card:hover {
            border-color: rgba(14, 165, 233, 0.3);
            box-shadow: 0 12px 35px -8px var(--accent-glow);
        }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            font-weight: 600; font-size: 0.8125rem; border-radius: 10px; padding: 0.6rem 1.2rem;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); cursor: pointer;
        }
        .btn-primary {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #ffffff;
            box-shadow: 0 4px 14px 0 rgba(2, 132, 199, 0.39);
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px 0 rgba(2, 132, 199, 0.55);
        }

        .btn-ghost {
            background: rgba(255, 255, 255, 0.05); color: #e2e8f0; border: 1px solid var(--border-color);
        }
        .btn-ghost:hover { background: rgba(255, 255, 255, 0.1); color: #ffffff; }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: #ffffff;
            box-shadow: 0 4px 14px 0 rgba(239, 68, 68, 0.35);
        }
        .btn-danger:hover { transform: translateY(-1px); box-shadow: 0 6px 20px 0 rgba(239, 68, 68, 0.5); }

        .field {
            width: 100%; background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border-color);
            border-radius: 10px; padding: 0.6rem 0.9rem; color: #f8fafc;
            font-size: 0.875rem; transition: all 0.2s ease;
        }
        .field:focus { outline: none; border-color: #38bdf8; background: rgba(15, 23, 42, 0.9); box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15); }

        .pill {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.25rem 0.75rem; border-radius: 9999px;
            font-size: 0.725rem; font-weight: 700;
        }
        .pill-live { background: rgba(34, 197, 94, 0.15); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.3); }
        .pill-off { background: rgba(148, 163, 184, 0.1); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.2); }
        .pill .dot { width: 0.45rem; height: 0.45rem; border-radius: 9999px; background-color: currentColor; animation: pulse 2s infinite; }

        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

        #toastWrap { position: fixed; top: 1.25rem; right: 1.25rem; z-index: 200; display: flex; flex-direction: column; gap: 0.6rem; }
        .toast {
            background: #1e293b; border: 1px solid var(--border-color); border-left: 4px solid #38bdf8;
            border-radius: 10px; padding: 0.85rem 1.1rem; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.5);
            opacity: 0; transform: translateX(30px); transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .toast.show { opacity: 1; transform: translateX(0); }

        .chat-bubble { padding: 0.65rem 0.9rem; border-radius: 12px; font-size: 0.8125rem; max-width: 85%; word-break: break-word; line-height: 1.4; }
        .chat-mine { background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color: #ffffff; align-self: flex-end; border-bottom-right-radius: 2px; }
        .chat-other { background: rgba(51, 65, 85, 0.7); color: #f1f5f9; align-self: flex-start; border-bottom-left-radius: 2px; border: 1px solid var(--border-color); }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.1); border-radius: 9999px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.25); }
    </style>
</head>
<body class="min-h-screen flex flex-col selection:bg-sky-500 selection:text-white">

    <div id="toastWrap"></div>

    <!-- ================= HEADER ================= -->
    <header id="appHeader" class="hidden border-b border-slate-800 bg-slate-900/80 backdrop-blur-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-3.5 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-sky-500 to-cyan-400 flex items-center justify-center text-white shadow-lg shadow-sky-500/20">
                    <i class="fa-solid fa-users-viewfinder text-lg"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-base font-extrabold tracking-tight text-white">MinuteMind <span class="text-sky-400">Pro</span></h1>
                        <span id="roleBadge" class="px-2 py-0.5 text-[10px] font-extrabold tracking-wider rounded-md bg-sky-500/10 text-sky-400 border border-sky-500/20 uppercase">HOST</span>
                    </div>
                    <span id="roomBadge" class="text-xs font-semibold text-slate-400">ROOM: ---</span>
                </div>
            </div>

            <div class="flex items-center space-x-4">
                <span id="ollamaPill" class="pill pill-off"><span class="dot"></span><span id="ollamaText">Ollama Off</span></span>
                <div class="flex items-center gap-2.5 bg-slate-800/80 px-3 py-1.5 rounded-xl border border-slate-700/60">
                    <div id="userAvatar" class="w-6 h-6 rounded-lg bg-sky-500 flex items-center justify-center font-bold text-xs text-white shadow-sm">U</div>
                    <span id="userNameDisplay" class="text-xs font-semibold text-slate-200">User</span>
                </div>
                <button id="terminateBtn" title="Terminate Room (Host)" class="btn btn-danger py-1.5 text-xs hidden"><i class="fa-solid fa-power-off"></i> Terminate</button>
                <button id="logoutBtn" title="Leave Room" class="btn btn-ghost py-1.5 text-xs"><i class="fa-solid fa-right-from-bracket"></i> Leave</button>
            </div>
        </div>
    </header>

    <!-- ================= AUTH & ROOM ENTRY SCREEN ================= -->
    <div id="authScreen" class="flex-1 flex items-center justify-center px-4 py-16">
        <div class="w-full max-w-md glass-card p-8 relative overflow-hidden">
            <div class="absolute -top-24 -left-24 w-48 h-48 bg-sky-500/10 rounded-full blur-3xl pointer-events-none"></div>
            
            <div class="text-center mb-8">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-sky-500 to-cyan-400 flex items-center justify-center mx-auto mb-4 text-white shadow-xl shadow-sky-500/20">
                    <i class="fa-solid fa-network-wired text-2xl"></i>
                </div>
                <h2 class="text-2xl font-extrabold tracking-tight text-white">MinuteMind Workspace</h2>
                <p class="text-xs font-medium text-slate-400 mt-1.5">Collaborative WebRTC & AI Minutes Engine</p>
            </div>

            <div class="flex rounded-xl bg-slate-900/80 p-1 mb-6 border border-slate-800">
                <button id="tabHostBtn" type="button" class="flex-1 py-2 text-xs font-bold rounded-lg bg-sky-600 text-white shadow-md transition-all">Host Session</button>
                <button id="tabMemberBtn" type="button" class="flex-1 py-2 text-xs font-bold rounded-lg text-slate-400 hover:text-white transition-all">Join Member</button>
            </div>

            <!-- HOST FORM -->
            <form id="hostAuthForm" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Host Name</label>
                    <input type="text" id="hostName" required class="field" placeholder="e.g. Alex Host">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Email Address</label>
                    <input type="email" id="hostEmail" required class="field" placeholder="alex@company.com">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Room Code</label>
                    <div class="flex gap-2">
                        <input type="text" id="hostRoomCode" required class="field uppercase font-mono tracking-wider" placeholder="ROOM1234">
                        <button type="button" id="genRoomBtn" class="btn btn-ghost shrink-0" title="Generate Code"><i class="fa-solid fa-dice text-base"></i></button>
                    </div>
                </div>
                <button type="submit" class="w-full btn btn-primary py-3 text-xs tracking-wide uppercase font-extrabold mt-2">Create & Host Room</button>
            </form>

            <!-- MEMBER FORM -->
            <form id="memberAuthForm" class="space-y-4 hidden">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Your Name</label>
                    <input type="text" id="memberName" required class="field" placeholder="e.g. Sarah Member">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Email Address (Optional)</label>
                    <input type="email" id="memberEmail" class="field" placeholder="sarah@company.com">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Target Room Code</label>
                    <input type="text" id="memberRoomCode" required class="field uppercase font-mono tracking-wider" placeholder="Enter Host Code">
                </div>
                <button type="submit" class="w-full btn btn-primary py-3 text-xs tracking-wide uppercase font-extrabold mt-2">Join Room Session</button>
            </form>
        </div>
    </div>

    <!-- ================= MAIN WORKSPACE ================= -->
    <main id="appWorkspace" class="hidden flex-1 max-w-7xl w-full mx-auto px-6 py-6">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- LEFT/MAIN COLUMN -->
            <div class="lg:col-span-8 space-y-6">

                <!-- HOST BOARD -->
                <div id="hostBoard" class="hidden space-y-6">
                    <div class="glass-card p-5">
                        <div class="flex justify-between items-center mb-3.5">
                            <span class="text-xs font-extrabold tracking-wider text-slate-300 uppercase"><i class="fa-solid fa-video text-sky-400 mr-2"></i> Host WebRTC Broadcast</span>
                            <span class="text-[11px] font-semibold text-slate-500">P2P Stream</span>
                        </div>
                        <div class="relative bg-slate-950 aspect-video rounded-xl flex items-center justify-center overflow-hidden border border-slate-800 shadow-inner">
                            <video id="hostVideoPlayer" class="w-full h-full object-cover hidden" autoplay playsinline muted></video>
                            <div id="videoPlaceholder" class="text-center text-slate-500 p-6">
                                <div class="w-12 h-12 rounded-full bg-slate-900 border border-slate-800 flex items-center justify-center mx-auto mb-3">
                                    <i class="fa-solid fa-video-slash text-xl text-slate-600"></i>
                                </div>
                                <p class="text-xs font-semibold">No active video stream</p>
                            </div>
                        </div>
                        <div class="flex gap-2.5 mt-4">
                            <button id="startCamBtn" class="btn btn-ghost text-xs"><i class="fa-solid fa-webcam text-sky-400"></i> Camera</button>
                            <label class="btn btn-ghost text-xs cursor-pointer">
                                <i class="fa-solid fa-folder-open text-sky-400"></i> Video File
                                <input type="file" id="videoFileInput" accept="video/*" class="hidden">
                            </label>
                            <button id="stopVideoBtn" class="btn btn-danger text-xs hidden"><i class="fa-solid fa-power-off"></i> Stop Stream</button>
                        </div>
                    </div>

                    <div class="glass-card p-5 space-y-3.5">
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-extrabold tracking-wider text-slate-300 uppercase"><i class="fa-solid fa-wand-magic-sparkles text-sky-400 mr-2"></i> Meeting Transcript Input</span>
                            <button id="loadSampleBtn" class="text-[11px] font-bold text-sky-400 bg-sky-500/10 px-2.5 py-1 rounded-lg border border-sky-500/20 hover:bg-sky-500/20 transition-all">Sample Text</button>
                        </div>
                        <textarea id="transcriptInput" rows="4" class="field resize-none leading-relaxed" placeholder="Paste transcript or notes here..."></textarea>
                        <button id="generateBtn" class="w-full btn btn-primary text-xs py-2.5"><i class="fa-solid fa-bolt"></i> Generate AI Minutes</button>
                    </div>

                    <div class="glass-card p-5 space-y-3.5">
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-extrabold tracking-wider text-slate-300 uppercase"><i class="fa-solid fa-file-lines text-sky-400 mr-2"></i> Formatted Minutes</span>
                            <button id="broadcastBtn" class="btn btn-primary text-[11px] py-1.5"><i class="fa-solid fa-share-nodes"></i> Share with Members</button>
                        </div>
                        <div id="formattedOutput" class="bg-slate-950/60 p-5 rounded-xl text-xs text-slate-300 border border-slate-800/80 min-h-[140px] prose prose-invert max-w-none leading-relaxed">
                            <em class="text-slate-500">Generated minutes will render here...</em>
                        </div>
                    </div>
                </div>

                <!-- MEMBER BOARD -->
                <div id="memberBoard" class="hidden space-y-6">
                    <div class="glass-card p-5">
                        <div class="flex justify-between items-center mb-3.5">
                            <span class="text-xs font-extrabold tracking-wider text-slate-300 uppercase"><i class="fa-solid fa-display text-sky-400 mr-2"></i> Host Broadcast Stream</span>
                            <span id="hostConnPill" class="pill pill-off"><span class="dot"></span><span id="hostConnText">Offline</span></span>
                        </div>
                        <div class="relative bg-slate-950 aspect-video rounded-xl flex items-center justify-center overflow-hidden border border-slate-800 shadow-inner">
                            <video id="memberVideoPlayer" class="w-full h-full object-cover hidden" autoplay playsinline controls muted></video>
                            <div id="memberVideoPlaceholder" class="text-center p-6 text-slate-500">
                                <i class="fa-solid fa-tower-broadcast text-3xl mb-2 text-slate-600"></i>
                                <p class="text-xs font-semibold">Awaiting host video broadcast...</p>
                            </div>
                        </div>
                    </div>

                    <div class="glass-card p-5">
                        <h3 class="text-xs font-extrabold tracking-wider text-slate-300 uppercase mb-3.5"><i class="fa-solid fa-newspaper text-sky-400 mr-2"></i> Published Meeting Minutes</h3>
                        <div id="memberFormattedOutput" class="bg-slate-950/60 p-5 rounded-xl text-xs text-slate-300 border border-slate-800/80 min-h-[160px] prose prose-invert max-w-none leading-relaxed">
                            <em class="text-slate-500">Awaiting host published notes...</em>
                        </div>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN -->
            <div class="lg:col-span-4 space-y-6">
                <div class="glass-card p-5">
                    <div class="flex justify-between items-center mb-3">
                        <span class="text-xs font-extrabold tracking-wider text-slate-300 uppercase"><i class="fa-solid fa-users text-sky-400 mr-2"></i> Active Members</span>
                        <span id="peerCountBadge" class="text-[11px] font-extrabold px-2.5 py-0.5 rounded-full bg-sky-500/10 text-sky-400 border border-sky-500/20">1</span>
                    </div>
                    <ul id="peerList" class="space-y-1.5 text-xs max-h-36 overflow-y-auto pr-1"></ul>
                </div>

                <!-- Chat System -->
                <div class="glass-card p-5 flex flex-col h-[500px]">
                    <div class="flex rounded-xl bg-slate-950/80 p-1 mb-3.5 border border-slate-800 shrink-0">
                        <button id="tabPublicChat" class="flex-1 py-1.5 text-[11px] font-bold rounded-lg bg-sky-600 text-white shadow-sm transition-all">Public Chat</button>
                        <button id="tabPrivateChat" class="flex-1 py-1.5 text-[11px] font-bold rounded-lg text-slate-400 hover:text-white transition-all">Private Direct</button>
                    </div>

                    <div id="privateTargetWrap" class="hidden mb-3 shrink-0">
                        <select id="privateTargetSelect" class="field text-xs py-1.5"></select>
                    </div>

                    <div id="chatBox" class="flex-1 bg-slate-950/60 rounded-xl border border-slate-800/80 p-3.5 overflow-y-auto flex flex-col gap-2.5 mb-3.5"></div>

                    <form id="chatForm" class="flex gap-2 shrink-0">
                        <input type="text" id="chatInput" class="field text-xs" placeholder="Type message..." required>
                        <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-paper-plane"></i></button>
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
        el.innerHTML = `<div><p class="font-extrabold text-xs text-white">${title}</p><p class="text-[11px] font-medium text-slate-400 mt-0.5">${msg || ''}</p></div>`;
        wrap.appendChild(el);
        requestAnimationFrame(() => el.classList.add('show'));
        setTimeout(() => { el.remove(); }, 3500);
    }

    $('tabHostBtn').addEventListener('click', () => {
        $('tabHostBtn').className = 'flex-1 py-2 text-xs font-bold rounded-lg bg-sky-600 text-white shadow-md transition-all';$('tabMemberBtn').className = 'flex-1 py-2 text-xs font-bold rounded-lg text-slate-400 hover:text-white transition-all';
        $('hostAuthForm').classList.remove('hidden');$('memberAuthForm').classList.add('hidden');
    });

    $('tabMemberBtn').addEventListener('click', () => {
        $('tabMemberBtn').className = 'flex-1 py-2 text-xs font-bold rounded-lg bg-sky-600 text-white shadow-md transition-all';$('tabHostBtn').className = 'flex-1 py-2 text-xs font-bold rounded-lg text-slate-400 hover:text-white transition-all';
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

            await sendToNetwork({ type: 'presence' });
            setTimeout(() => sendToNetwork({ type: 'presence' }), 500);

            setInterval(pollNetworkSignals, 1000);
            setInterval(sendPresence, 3000);

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
        selfItem.className = 'text-sky-400 font-bold flex items-center justify-between py-1 px-2 rounded-lg bg-sky-500/10 border border-sky-500/20';
        selfItem.textContent = `${MY.name} (${MY.role.toUpperCase()}) - You`;
        list.appendChild(selfItem);

        let count = 1;
        peers.forEach((p, id) => {
            count++;
            const item = document.createElement('li');
            item.className = 'text-slate-300 font-medium py-1 px-2 rounded-lg bg-slate-900/60 border border-slate-800';
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
        $('tabPublicChat').className = 'flex-1 py-1.5 text-[11px] font-bold rounded-lg bg-sky-600 text-white shadow-sm transition-all';
        $('tabPrivateChat').className = 'flex-1 py-1.5 text-[11px] font-bold rounded-lg text-slate-400 hover:text-white transition-all';$('privateTargetWrap').classList.add('hidden');
    });

    $('tabPrivateChat').addEventListener('click', () => {
        activeChatTab = 'private';
        $('tabPrivateChat').className = 'flex-1 py-1.5 text-[11px] font-bold rounded-lg bg-sky-600 text-white shadow-sm transition-all';
        $('tabPublicChat').className = 'flex-1 py-1.5 text-[11px] font-bold rounded-lg text-slate-400 hover:text-white transition-all';$('privateTargetWrap').classList.remove('hidden');
    });

    $('chatForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const text = $('chatInput').value.trim();
        if (!text) return;

        if (activeChatTab === 'public') {
            await sendToNetwork({ type: 'public-chat', text: text });
            appendChatMessage({ senderName: MY.name, text: text, isMine: true, isPrivate: false });
        } else {
            const targetId = $('privateTargetSelect').value;
            if (!targetId) { toast('Error', 'Select a room member.'); return; }
            await sendToNetwork({ type: 'private-chat', targetTabId: targetId, text: text });
            appendChatMessage({ senderName: `To ${peers.get(targetId)?.name || 'User'}`, text: text, isMine: true, isPrivate: true });
        }
        $('chatInput').value = '';
    });

    function appendChatMessage(msg) {
        const box = $('chatBox');
        const el = document.createElement('div');
        el.className = `chat-bubble ${msg.isMine ? 'chat-mine' : 'chat-other'}`;

        const sender = document.createElement('div');
        sender.className = 'font-bold text-[10px] opacity-80 mb-0.5';
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

                if (!peers.has(m.from)) {
                    sendToNetwork({ type: 'presence' });
                }

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
