<?php
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// ---------- LAN MULTI-ROOM RELAY BACKEND ----------
$storageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mmpro_rooms';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0777, true);
}

// API Routes Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {

    // 1. Post a Message or WebRTC Signaling Payload to a Room
    if ($_GET['action'] === 'send_signal') {
        header('Content-Type: application/json');
        $input = file_get_json_input();
        $roomCode = preg_replace('/[^A-Za-z0-9_\-]/', '', $input['roomCode'] ?? '');

        if (!$roomCode) {
            echo json_encode(['error' => 'Invalid or missing roomCode']);
            exit;
        }

        $roomFile = $storageDir . DIRECTORY_SEPARATOR . $roomCode . '.json';
        $messages = file_exists($roomFile) ? json_decode(file_get_contents($roomFile), true) : [];
        if (!is_array($messages)) $messages = [];

        // Prune logs older than 2 hours to keep files tiny
        $cutoff = time() - 7200;
        $messages = array_filter($messages, fn($m) => ($m['ts'] ?? 0) > $cutoff);

        $messages[] = $input;
        file_put_contents($roomFile, json_encode(array_values($messages)), LOCK_EX);

        echo json_encode(['success' => true]);
        exit;
    }

    // 2. Poll Signals from a Room
    if ($_GET['action'] === 'poll_signals') {
        header('Content-Type: application/json');
        $input = file_get_json_input();
        $roomCode = preg_replace('/[^A-Za-z0-9_\-]/', '', $input['roomCode'] ?? '');
        $since = (int)($input['since'] ?? 0);

        $roomFile = $storageDir . DIRECTORY_SEPARATOR . $roomCode . '.json';
        $messages = file_exists($roomFile) ? json_decode(file_get_contents($roomFile), true) : [];
        if (!is_array($messages)) $messages = [];

        $newMessages = array_filter($messages, fn($m) => ($m['ts'] ?? 0) > $since);
        echo json_encode(['messages' => array_values($newMessages)]);
        exit;
    }

    // 3. Lightweight Ollama health check
    if ($_GET['action'] === 'ping') {
        header('Content-Type: application/json');
        $ch = curl_init('http://127.0.0.1:11434/api/tags');
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

    // 4. AI Minutes generation via local Ollama
    if ($_GET['action'] === 'generate') {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        $inputJSON = file_get_json_input();
        $transcript = $inputJSON['transcript'] ?? '';
        $tags = $inputJSON['tags'] ?? 'General';
        $customPrompt = $inputJSON['custom_prompt'] ?? '';

        if (empty(trim($transcript))) {
            echo json_encode(['error' => 'Transcript content cannot be empty.']);
            exit;
        }

        $ollama_url = 'http://127.0.0.1:11434/api/generate';

        $system_prompt = "You are an expert executive assistant and project management professional. "
            . "Analyze meeting transcripts and generate structured, professional Meeting Minutes in clean Markdown format. "
            . "Include: Executive Summary, Key Decisions, Action Items (with assignees), and Main Topics Discussed.";

        if (!empty($customPrompt)) {
            $system_prompt .= " Additional Context/Instructions: " . $customPrompt;
        }

        $prompt_content = "Context Tags: " . $tags . "\n\nMeeting Transcript:\n" . $transcript;

        $payload = [
            'model'  => 'qwen2.5-coder:7b',
            'system' => $system_prompt,
            'prompt' => $prompt_content,
            'stream' => false
        ];

        $ch = curl_init($ollama_url);
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
            echo json_encode(['error' => 'cURL Error: ' . $curl_error . ' - Verify local Ollama port 11434.']);
            exit;
        }
        if ($http_code !== 200) {
            echo json_encode(['error' => "Ollama returned HTTP status {$http_code}. Response: " . $response]);
            exit;
        }

        $data = json_decode($response, true);
        if (isset($data['response'])) {
            echo json_encode(['success' => true, 'minutes' => $data['response']]);
        } else {
            echo json_encode(['error' => 'Failed to extract LLM response: ' . $response]);
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
    <title>MinuteMind Pro — Multi-Room Network Workspace</title>

    <!-- Fonts & Assets -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>

    <style>
       :root {
            --bg-dark: #0a0c10;
            --metal-dark: #161a22;
            --metal-light: #3a4150;
            --accent: #00f0ff;
            --accent-magenta: #ff007f;
            --accent-lime: #39ff14;
            --accent-gold: #e6c687;
        }

        body {
            font-family: 'Trebuchet MS', 'Segoe UI', -apple-system, sans-serif;
            background-color: var(--bg-dark);
            color: #d1d5db;
            background-image: 
                radial-gradient(ellipse at 50% 0%, rgba(58, 65, 80, 0.3) 0%, transparent 75%),
                linear-gradient(180deg, #0d1017 0%, #05070a 100%);
            background-attachment: fixed;
        }

        .bg-grid {
            position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
            background-size: 20px 20px;
        }

        .glass-panel {
            position: relative;
            background: linear-gradient(180deg, #252b37 0%, #171c26 49%, #0d1118 50%, #1a202c 100%);
            border: 1px solid #4a5568;
            border-top: 1px solid #8a99ad;
            border-left: 1px solid #6b7c93;
            border-radius: 8px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.3), 0 8px 24px rgba(0, 0, 0, 0.8);
        }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            font-weight: 700; font-size: .75rem; border-radius: 4px; padding: .5rem 1.1rem;
            transition: all .15s ease; cursor: pointer; text-transform: uppercase;
            letter-spacing: .05em; text-shadow: 0 -1px 0 rgba(0,0,0,0.8);
        }
        .btn:active { transform: translateY(1px); }
        .btn:disabled { opacity: .4; cursor: not-allowed; }

        .btn-primary {
            background: linear-gradient(180deg, #00f0ff 0%, #0099cc 49%, #006699 50%, #004466 100%);
            color: #fff; border: 1px solid #003344; border-top: 1px solid #80f8ff;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.6), 0 4px 10px rgba(0, 0, 0, 0.6);
        }
        .btn-ghost {
            background: linear-gradient(180deg, #525e71 0%, #363e4b 49%, #222731 50%, #323a47 100%);
            color: #e2e8f0; border: 1px solid #1a202c; border-top: 1px solid #9aa8bc;
        }

        .field {
            width: 100%;
            background: linear-gradient(180deg, #080a0f 0%, #0f141d 100%);
            border: 1px solid #2d3748; border-bottom: 1px solid #4a5568;
            border-radius: 4px; padding: .6rem .8rem; color: #00f0ff;
            font-family: 'Courier New', monospace; font-size: .8rem; font-weight: bold;
            box-shadow: inset 0 2px 5px rgba(0,0,0,0.9);
        }
        .field:focus { outline: none; border-color: var(--accent); }

        .pill {
            display: inline-flex; align-items: center; gap: .45rem;
            padding: .25rem .7rem; border-radius: 3px;
            font-size: .68rem; font-weight: 800; text-transform: uppercase;
            background: linear-gradient(180deg, #1f242d 0%, #0d1015 100%);
            border: 1px solid #3a4250;
        }
        .pill .dot { width: .5rem; height: .5rem; border-radius: 9999px; box-shadow: 0 0 6px currentColor; }
        .pill-live { color: var(--accent-lime); }
        .pill-off { color: #718096; }

        #toastWrap { position: fixed; top: 1rem; right: 1rem; z-index: 200; display: flex; flex-direction: column; gap: .6rem; }
        .toast {
            display: flex; gap: .7rem; align-items: center; background: #181d26;
            border: 1px solid #5a6578; border-left: 4px solid var(--accent);
            border-radius: 5px; padding: .8rem; opacity: 0; transform: translateX(20px);
            transition: all .25s ease;
        }
        .toast.show { opacity: 1; transform: translateX(0); }

        .chat-bubble {
            padding: .5rem .75rem; border-radius: 6px; font-size: .75rem; max-width: 85%; word-break: break-word;
        }
        .chat-mine { background: #004466; color: #e0f7fa; align-self: flex-end; border: 1px solid #0088cc; }
        .chat-other { background: #1f242d; color: #d1d5db; align-self: flex-start; border: 1px solid #3a4250; }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <div class="bg-grid"></div>
    <div id="toastWrap"></div>

    <!-- ================= HEADER ================= -->
    <header id="appHeader" class="hidden border-b border-slate-800 bg-slate-900/80 sticky top-0 z-50 backdrop-blur">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <i class="fa-solid fa-users-viewfinder text-cyan-400 text-xl"></i>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-base font-extrabold text-white uppercase tracking-wider">MinuteMind Pro</h1>
                        <span id="roleBadge" class="px-2 py-0.5 text-[10px] font-bold rounded bg-cyan-500/20 text-cyan-300 border border-cyan-500/30">HOST</span>
                        <span id="roomBadge" class="px-2 py-0.5 text-[10px] font-bold rounded bg-purple-500/20 text-purple-300 border border-purple-500/30">ROOM: ---</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center space-x-3">
                <span id="ollamaPill" class="pill pill-off"><span class="dot"></span><span id="ollamaText">Ollama Off</span></span>
                <div class="flex items-center gap-2 bg-slate-800 p-1.5 rounded border border-slate-700">
                    <div id="userAvatar" class="w-6 h-6 rounded bg-cyan-600 flex items-center justify-center font-bold text-xs text-white">U</div>
                    <span id="userNameDisplay" class="text-xs font-semibold text-slate-200">User</span>
                    <button id="logoutBtn" title="Exit Room" class="text-slate-400 hover:text-rose-400 ml-2"><i class="fa-solid fa-right-from-bracket text-xs"></i></button>
                </div>
            </div>
        </div>
    </header>

    <!-- ================= AUTH & ROOM ENTRY SCREEN ================= -->
    <div id="authScreen" class="flex-1 flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-md glass-panel p-8 rounded-xl shadow-2xl">
            <div class="text-center mb-6">
                <i class="fa-solid fa-network-wired text-4xl text-cyan-400 mb-2"></i>
                <h2 class="text-xl font-bold text-white">MinuteMind Workspace</h2>
                <p class="text-xs text-slate-400 mt-1">Join or Host a Room Session across LAN</p>
            </div>

            <!-- Role Selector -->
            <div class="flex rounded bg-slate-950 p-1 mb-6 border border-slate-800">
                <button id="tabHostBtn" type="button" class="flex-1 py-2 text-xs font-bold rounded bg-cyan-600 text-white">Host Session (Login Required)</button>
                <button id="tabMemberBtn" type="button" class="flex-1 py-2 text-xs font-bold rounded text-slate-400 hover:text-white">Join as Member</button>
            </div>

            <!-- HOST FORM (Mandatory Login) -->
            <form id="hostAuthForm" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Host Name</label>
                    <input type="text" id="hostName" required class="field" placeholder="Alex (Host)">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Host Email Address</label>
                    <input type="email" id="hostEmail" required class="field" placeholder="alex@company.com">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Room Code (Create or Rejoin)</label>
                    <div class="flex gap-2">
                        <input type="text" id="hostRoomCode" required class="field uppercase" placeholder="e.g. ROOM1234">
                        <button type="button" id="genRoomBtn" class="btn btn-ghost shrink-0"><i class="fa-solid fa-dice"></i></button>
                    </div>
                </div>
                <button type="submit" class="w-full btn btn-primary py-3 text-xs">Start Host Session</button>
            </form>

            <!-- MEMBER FORM (Optional Account or Quick Guest) -->
            <form id="memberAuthForm" class="space-y-4 hidden">
                <div class="flex gap-2 mb-2">
                    <label class="text-[11px] text-slate-400 flex items-center gap-1">
                        <input type="radio" name="memberAuthType" value="guest" checked> Quick Guest
                    </label>
                    <label class="text-[11px] text-slate-400 flex items-center gap-1">
                        <input type="radio" name="memberAuthType" value="account"> Account Login
                    </label>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Your Name</label>
                    <input type="text" id="memberName" required class="field" placeholder="Sarah (Member)">
                </div>
                <div id="memberEmailWrap" class="hidden">
                    <label class="block text-xs font-medium text-slate-300 mb-1">Email Address</label>
                    <input type="email" id="memberEmail" class="field" placeholder="sarah@company.com">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Target Room Code</label>
                    <input type="text" id="memberRoomCode" required class="field uppercase" placeholder="Enter Host Code">
                </div>
                <button type="submit" class="w-full btn btn-primary py-3 text-xs">Enter Room</button>
            </form>
        </div>
    </div>

    <!-- ================= MAIN WORKSPACE ================= -->
    <main id="appWorkspace" class="hidden flex-1 max-w-7xl w-full mx-auto px-4 py-6">

        <!-- WORKSPACE GRID -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- LEFT/MAIN COLUMN (Role Specific) -->
            <div class="lg:col-span-8 space-y-6">

                <!-- HOST BOARD -->
                <div id="hostBoard" class="hidden space-y-6">
                    <div class="glass-panel p-4 rounded-xl">
                        <div class="flex justify-between items-center mb-3">
                            <span class="text-xs font-bold text-cyan-400 uppercase"><i class="fa-solid fa-video mr-1"></i> Host Stream</span>
                            <span class="text-[10px] text-slate-400">P2P Direct WebRTC</span>
                        </div>
                        <div class="relative bg-slate-950 aspect-video rounded border border-slate-800 flex items-center justify-center overflow-hidden">
                            <video id="hostVideoPlayer" class="w-full h-full object-cover hidden" autoplay playsinline muted></video>
                            <div id="videoPlaceholder" class="text-center">
                                <i class="fa-solid fa-camera text-3xl text-slate-600 mb-2"></i>
                                <p class="text-xs text-slate-400">No stream started</p>
                            </div>
                        </div>
                        <div class="flex gap-2 mt-3">
                            <button id="startCamBtn" class="btn btn-ghost"><i class="fa-solid fa-webcam"></i> Camera</button>
                            <label class="btn btn-ghost cursor-pointer">
                                <i class="fa-solid fa-folder-open"></i> Video File
                                <input type="file" id="videoFileInput" accept="video/*" class="hidden">
                            </label>
                            <button id="stopVideoBtn" class="btn btn-ghost hidden text-rose-400"><i class="fa-solid fa-power-off"></i> Stop</button>
                        </div>
                    </div>

                    <!-- AI Minutes Generator -->
                    <div class="glass-panel p-4 rounded-xl space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-bold text-cyan-400 uppercase"><i class="fa-solid fa-wand-magic-sparkles mr-1"></i> Meeting Notes / AI Input</span>
                            <button id="loadSampleBtn" class="text-[10px] text-cyan-300 bg-cyan-950 px-2 py-0.5 rounded border border-cyan-800">Sample Notes</button>
                        </div>
                        <textarea id="transcriptInput" rows="5" class="field resize-none" placeholder="Paste notes/transcript here..."></textarea>
                        <button id="generateBtn" class="w-full btn btn-primary py-2.5 text-xs"><i class="fa-solid fa-bolt"></i> Generate AI Minutes</button>
                    </div>

                    <!-- Formatted Output & Publish -->
                    <div class="glass-panel p-4 rounded-xl space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-bold text-slate-200 uppercase"><i class="fa-solid fa-file-lines mr-1"></i> Minutes Draft</span>
                            <button id="broadcastBtn" class="btn btn-primary text-[10px]"><i class="fa-solid fa-share-nodes"></i> Share with Room Members</button>
                        </div>
                        <div id="formattedOutput" class="bg-slate-900 p-4 rounded text-xs text-slate-200 border border-slate-800 min-h-[120px] prose">
                            <em class="text-slate-500">Generated minutes will render here...</em>
                        </div>
                    </div>
                </div>

                <!-- MEMBER BOARD -->
                <div id="memberBoard" class="hidden space-y-6">
                    <div class="glass-panel p-4 rounded-xl">
                        <div class="flex justify-between items-center mb-3">
                            <span class="text-xs font-bold text-emerald-400 uppercase"><i class="fa-solid fa-display mr-1"></i> Room Video Stream</span>
                            <span id="hostConnPill" class="pill pill-off"><span class="dot"></span><span id="hostConnText">Host Feed Offline</span></span>
                        </div>
                        <div class="relative bg-slate-950 aspect-video rounded border border-slate-800 flex items-center justify-center overflow-hidden">
                            <video id="memberVideoPlayer" class="w-full h-full object-cover hidden" autoplay playsinline controls muted></video>
                            <div id="memberVideoPlaceholder" class="text-center p-4">
                                <i class="fa-solid fa-tower-broadcast text-3xl text-slate-600 mb-2"></i>
                                <p class="text-xs text-slate-400">Not connected to Host feed</p>
                            </div>
                        </div>
                    </div>

                    <div class="glass-panel p-4 rounded-xl">
                        <h3 class="text-xs font-bold text-slate-200 uppercase mb-3"><i class="fa-solid fa-newspaper mr-1"></i> Published Meeting Minutes</h3>
                        <div id="memberFormattedOutput" class="bg-slate-900 p-4 rounded text-xs text-slate-200 border border-slate-800 min-h-[150px] prose">
                            <em class="text-slate-500">Awaiting minutes published by host...</em>
                        </div>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN (Public & Private Room Chat) -->
            <div class="lg:col-span-4 space-y-6">

                <!-- Room Participant List -->
                <div class="glass-panel p-4 rounded-xl">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-xs font-bold text-slate-300 uppercase"><i class="fa-solid fa-users mr-1"></i> Room Members</span>
                        <span id="peerCountBadge" class="text-[10px] font-bold px-2 py-0.5 rounded bg-cyan-950 text-cyan-300 border border-cyan-800">1</span>
                    </div>
                    <ul id="peerList" class="space-y-1 text-xs max-h-32 overflow-y-auto"></ul>
                </div>

                <!-- Chat System -->
                <div class="glass-panel p-4 rounded-xl flex flex-col h-[480px]">
                    
                    <!-- Chat Mode Tabs -->
                    <div class="flex rounded bg-slate-950 p-1 mb-3 border border-slate-800 shrink-0">
                        <button id="tabPublicChat" class="flex-1 py-1 text-[11px] font-bold rounded bg-cyan-600 text-white">Public Chat</button>
                        <button id="tabPrivateChat" class="flex-1 py-1 text-[11px] font-bold rounded text-slate-400 hover:text-white">Private Direct</button>
                    </div>

                    <!-- Target user dropdown for private chat -->
                    <div id="privateTargetWrap" class="hidden mb-2 shrink-0">
                        <label class="block text-[10px] text-slate-400 mb-1">Direct Message To:</label>
                        <select id="privateTargetSelect" class="field text-xs py-1"></select>
                    </div>

                    <!-- Chat Message Area -->
                    <div id="chatBox" class="flex-1 bg-slate-950 rounded border border-slate-800 p-3 overflow-y-auto flex flex-col gap-2 mb-3"></div>

                    <!-- Chat Input Form -->
                    <form id="chatForm" class="flex gap-2 shrink-0">
                        <input type="text" id="chatInput" class="field text-xs py-2" placeholder="Type room message..." required>
                        <button type="submit" class="btn btn-primary px-3"><i class="fa-solid fa-paper-plane"></i></button>
                    </form>
                </div>

            </div>
        </div>
    </main>

    <script>
    /* =========================================================
       MinuteMind Pro — P2P WebRTC & Network Signaling Engine
       ========================================================= */

    const MY = {
        tabId: (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'id-' + Date.now() + '-' + Math.random().toString(16).slice(2),
        role: null, name: null, email: null, roomCode: null
    };

    let lastPolledTimestamp = 0;
    let currentMarkdownResult = "";
    const peers = new Map(); // tabId -> { name, role, roomCode, lastSeen }
    let activeChatTab = 'public'; // 'public' | 'private'

    // PeerConnections map (targetTabId -> RTCPeerConnection)
    const rtcConnections = new Map();
    let localStream = null;

    const rtcConfig = {
        iceServers: [{ urls: 'stun:stun.l.google.com:19302' }]
    };

    // ---------- Network Signal Dispatcher ----------
    async function sendToNetwork(payload) {
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
            console.error('Network signal error:', e);
        }
    }

    // ---------- Network Room Polling Listener ----------
    async function pollNetworkSignals() {
        if (!MY.roomCode) return;

        try {
            const res = await fetch('index.php?action=poll_signals', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ roomCode: MY.roomCode, since: lastPolledTimestamp })
            });
            const data = await res.json();

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

    // ---------- Ollama Health Poller ----------
    async function checkOllamaStatus() {
        try {
            const res = await fetch('index.php?action=ping', { method: 'POST' });
            const data = await res.json();
            const pill = $('ollamaPill');
            const text = $('ollamaText');

            if (data.ollama) {
                pill.className = 'pill pill-live';
                text.textContent = 'Ollama Online';
            } else {
                pill.className = 'pill pill-off';
                text.textContent = 'Ollama Off';
            }
        } catch (e) {
            $('ollamaPill').className = 'pill pill-off';
            $('ollamaText').textContent = 'Ollama Off';
        }
    }

    // ---------- UI Toast System ----------
    function toast(title, msg) {
        const wrap = document.getElementById('toastWrap');
        const el = document.createElement('div');
        el.className = 'toast';
        el.innerHTML = `<div><p class="font-bold text-xs text-white uppercase">${title}</p><p class="text-[11px] text-slate-300">${msg || ''}</p></div>`;
        wrap.appendChild(el);
        requestAnimationFrame(() => el.classList.add('show'));
        setTimeout(() => { el.remove(); }, 3500);
    }

    // ---------- DOM Direct Access ----------
    const $ = (id) => document.getElementById(id);

    // ---------- Auth Tab Handlers ----------
    $('tabHostBtn').addEventListener('click', () => {
        $('tabHostBtn').className = 'flex-1 py-2 text-xs font-bold rounded bg-cyan-600 text-white';$('tabMemberBtn').className = 'flex-1 py-2 text-xs font-bold rounded text-slate-400 hover:text-white';
        $('hostAuthForm').classList.remove('hidden');$('memberAuthForm').classList.add('hidden');
    });

    $('tabMemberBtn').addEventListener('click', () => {
        $('tabMemberBtn').className = 'flex-1 py-2 text-xs font-bold rounded bg-cyan-600 text-white';$('tabHostBtn').className = 'flex-1 py-2 text-xs font-bold rounded text-slate-400 hover:text-white';
        $('memberAuthForm').classList.remove('hidden');$('hostAuthForm').classList.add('hidden');
    });

    $('genRoomBtn').addEventListener('click', () => {$('hostRoomCode').value = 'ROOM' + Math.floor(1000 + Math.random() * 9000);
    });

    document.querySelectorAll('input[name="memberAuthType"]').forEach(radio => {
        radio.addEventListener('change', (e) => {
            if (e.target.value === 'account') $('memberEmailWrap').classList.remove('hidden');
            else $('memberEmailWrap').classList.add('hidden');
        });
    });

    // ---------- Workspace Login Handler ----------
    function enterWorkspace(user) {
        MY.role = user.role;
        MY.name = user.name;
        MY.email = user.email || 'Guest';
        MY.roomCode = user.roomCode.toUpperCase().replace(/[^A-Z0-9]/g, '');

        $('userNameDisplay').textContent = MY.name;
        $('userAvatar').textContent = MY.name.charAt(0).toUpperCase();
        $('roleBadge').textContent = MY.role.toUpperCase();$('roomBadge').textContent = 'ROOM: ' + MY.roomCode;

        $('authScreen').classList.add('hidden');
        $('appHeader').classList.remove('hidden');$('appWorkspace').classList.remove('hidden');

        if (MY.role === 'host') {
            $('hostBoard').classList.remove('hidden');$('memberBoard').classList.add('hidden');
        } else {
            $('memberBoard').classList.remove('hidden');$('hostBoard').classList.add('hidden');
        }

        // Fast presence dispatch & setup background polling loops
        sendToNetwork({ type: 'presence' });
        setInterval(pollNetworkSignals, 1500);
        setInterval(sendPresence, 4000);

        checkOllamaStatus();
        setInterval(checkOllamaStatus, 10000);

        toast('Joined Room', `Logged in as ${MY.name} in room ${MY.roomCode}`);
        renderPeerList();
    }

    function sendPresence() {
        if (MY.roomCode) {
            sendToNetwork({ type: 'presence' });
            prunePeers();
        }
    }

    $('hostAuthForm').addEventListener('submit', (e) => {
        e.preventDefault();
        enterWorkspace({
            role: 'host',
            name: $('hostName').value,
            email: $('hostEmail').value,
            roomCode: $('hostRoomCode').value
        });
    });

    $('memberAuthForm').addEventListener('submit', (e) => {
        e.preventDefault();
        enterWorkspace({
            role: 'member',
            name: $('memberName').value,
            email: $('memberEmail').value,
            roomCode: $('memberRoomCode').value
        });
    });

    $('logoutBtn').addEventListener('click', () => {
        stopBroadcast();
        sendToNetwork({ type: 'bye' });
        location.reload();
    });

    // ---------- Presence Heartbeat & Filtering ----------
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
        selfItem.className = 'text-cyan-400 font-bold';
        selfItem.textContent = `${MY.name} (${MY.role.toUpperCase()}) - You`;
        list.appendChild(selfItem);

        let count = 1;
        peers.forEach((p, id) => {
            count++;
            const item = document.createElement('li');
            item.className = 'text-slate-300';
            item.textContent = `${p.name} (${p.role.toUpperCase()})`;
            list.appendChild(item);

            const opt = document.createElement('option');
            opt.value = id;
            opt.textContent = `${p.name} (${p.role.toUpperCase()})`;
            select.appendChild(opt);
        });

        $('peerCountBadge').textContent = count;
    }

    // ---------- Chat System ----------
    $('tabPublicChat').addEventListener('click', () => {
        activeChatTab = 'public';
        $('tabPublicChat').className = 'flex-1 py-1 text-[11px] font-bold rounded bg-cyan-600 text-white';
        $('tabPrivateChat').className = 'flex-1 py-1 text-[11px] font-bold rounded text-slate-400 hover:text-white';$('privateTargetWrap').classList.add('hidden');
    });

    $('tabPrivateChat').addEventListener('click', () => {
        activeChatTab = 'private';
        $('tabPrivateChat').className = 'flex-1 py-1 text-[11px] font-bold rounded bg-cyan-600 text-white';
        $('tabPublicChat').className = 'flex-1 py-1 text-[11px] font-bold rounded text-slate-400 hover:text-white';$('privateTargetWrap').classList.remove('hidden');
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
            if (!targetId) { toast('Private Chat Error', 'Select a room member first.'); return; }
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
        sender.className = 'font-bold text-[10px] text-cyan-300 mb-0.5';
        sender.textContent = `${msg.senderName} ${msg.isPrivate ? '(Private Direct)' : ''}`;

        const content = document.createElement('div');
        content.textContent = msg.text;

        el.appendChild(sender);
        el.appendChild(content);
        box.appendChild(el);
        box.scrollTop = box.scrollHeight;
    }

    // ---------- Zero-Load WebRTC Direct Stream Broadcasting ----------
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

        // Initiate RTC connection to existing members in room
        peers.forEach((p, memberId) => {
            if (p.role === 'member') {
                initiatePeerConnection(memberId);
            }
        });

        sendToNetwork({ type: 'stream-started' });
        toast('Broadcasting', 'Stream live via peer-to-peer connection.');
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
        toast('Stream Stopped', 'Host camera broadcast ended.');
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
                
                $('hostConnPill').className = 'pill pill-live';$('hostConnText').textContent = 'Host Streaming Live';
            };

            pc.oniceconnectionstatechange = () => {
                if (['disconnected', 'failed', 'closed'].includes(pc.iceConnectionState)) {
                    $('hostConnPill').className = 'pill pill-off';$('hostConnText').textContent = 'Host Feed Offline';
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

    // ---------- Network Signal Router ----------
    async function handleIncomingSignal(m) {
        switch (m.type) {
            case 'presence':
                peers.set(m.from, { name: m.senderName, role: m.senderRole, lastSeen: Date.now() });
                renderPeerList();

                // If host is active and local streaming is on, establish P2P link with new member
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
                    toast('Private Message', `New private message from ${m.senderName}`);
                }
                break;

            case 'published-minutes':
                if (MY.role === 'member') {
                    $('memberFormattedOutput').innerHTML = DOMPurify.sanitize(marked.parse(m.markdown));
                    toast('Minutes Received', `Host ${m.senderName} published meeting minutes.`);
                }
                break;

            case 'stream-started':
                if (MY.role === 'member') {
                    toast('Host Live', `${m.senderName} started broadcasting stream.`);
                }
                break;

            case 'stream-stopped':
                if (MY.role === 'member') {
                    const memberVideo = $('memberVideoPlayer');
                    memberVideo.srcObject = null;
                    memberVideo.classList.add('hidden');
                    $('memberVideoPlaceholder').classList.remove('hidden');

                    $('hostConnPill').className = 'pill pill-off';$('hostConnText').textContent = 'Host Feed Offline';
                    toast('Stream Ended', 'Host stopped video broadcast.');
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
                    if (pc) {
                        await pc.setRemoteDescription(new RTCSessionDescription(m.answer));
                    }
                }
                break;

            case 'rtc-candidate':
                if (m.targetTabId === MY.tabId) {
                    const pc = rtcConnections.get(m.from);
                    if (pc && m.candidate) {
                        await pc.addIceCandidate(new RTCIceCandidate(m.candidate));
                    }
                }
                break;
        }
    }

    // ---------- AI Minutes Handler (Host) ----------
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
            toast('AI Complete', 'Meeting minutes generated.');
        } catch (err) {
            toast('AI Error', err.message);
        }
    });

    $('broadcastBtn')?.addEventListener('click', () => {
        if (!currentMarkdownResult) return toast('Warning', 'Generate minutes first');
        sendToNetwork({ type: 'published-minutes', markdown: currentMarkdownResult });
        toast('Published', 'Minutes sent to all room members across LAN.');
    });

    window.addEventListener('beforeunload', () => { 
        stopBroadcast();
        sendToNetwork({ type: 'bye' }); 
    });
    </script>
</body>
</html>