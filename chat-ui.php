<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/chat.php';
redirectIfNotLoggedIn();

$chatSystem = new ChatSystem($conn);
$current_user_id = $_SESSION['user_id'];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'send':
                $response = $chatSystem->sendMessage(
                    $current_user_id,
                    $_POST['receiver_id'],
                    $_POST['message'],
                    $_POST['product_id'] ?? null
                );
                echo json_encode(['success' => $response]);
                exit;
                
            case 'get_messages':
                $messages = $chatSystem->getConversation(
                    $current_user_id,
                    $_POST['partner_id']
                );
                echo json_encode($messages);
                exit;
                
            case 'mark_read':
                $chatSystem->markAsRead($_POST['message_ids']);
                echo json_encode(['success' => true]);
                exit;
        }
    }
}

// Get chat list for sidebar
$chat_list = $chatSystem->getChatList($current_user_id);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Messages | Elixir Hub</title>
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .chat-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            height: 80vh;
            max-width: 1200px;
            margin: 20px auto;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .chat-sidebar {
            background: #f8f9fa;
            border-right: 1px solid #eee;
            overflow-y: auto;
        }
        .chat-main {
            display: flex;
            flex-direction: column;
            background: white;
        }
        .chat-header {
            padding: 15px;
            border-bottom: 1px solid #eee;
            background: #6c5ce7;
            color: white;
        }
        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: #f5f6fa;
        }
        .message-input {
            padding: 15px;
            border-top: 1px solid #eee;
            background: white;
        }
        .chat-list-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: all 0.2s;
        }
        .chat-list-item:hover {
            background: #e9ecef;
        }
        .chat-list-item.active {
            background: #dee2e6;
        }
        .unread-count {
            background: #ff4757;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            margin-left: auto;
        }
        .message {
            margin-bottom: 15px;
            max-width: 70%;
            padding: 10px 15px;
            border-radius: 18px;
            position: relative;
            animation: fadeIn 0.3s ease;
        }
        .message.sent {
            background: #6c5ce7;
            color: white;
            margin-left: auto;
            border-bottom-right-radius: 0;
        }
        .message.received {
            background: #e9ecef;
            margin-right: auto;
            border-bottom-left-radius: 0;
        }
        .message-time {
            font-size: 12px;
            opacity: 0.7;
            margin-top: 5px;
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        #messageInput {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 20px;
            outline: none;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="chat-container">
        <!-- Sidebar with chat list -->
        <div class="chat-sidebar">
            <div class="chat-header">
                <h3>Your Conversations</h3>
            </div>
            <?php foreach ($chat_list as $chat): ?>
                <div class="chat-list-item" 
                     onclick="loadConversation(<?= $chat['partner_id'] ?>)"
                     id="chat-<?= $chat['partner_id'] ?>">
                    <strong><?= htmlspecialchars($chat['partner_name']) ?></strong>
                    <small>(<?= ucfirst($chat['partner_role']) ?>)</small>
                    <?php if ($chat['unread_count'] > 0): ?>
                        <span class="unread-count"><?= $chat['unread_count'] ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Main chat area -->
        <div class="chat-main">
            <div class="chat-header" id="currentChatHeader">
                <h3>Select a conversation</h3>
            </div>
            
            <div class="chat-messages" id="messageContainer">
                <div class="empty-state">
                    <p>Select a conversation to start chatting</p>
                </div>
            </div>
            
            <div class="message-input">
                <input type="text" id="messageInput" placeholder="Type your message..." disabled>
                <button id="sendButton" disabled>Send</button>
            </div>
        </div>
    </div>

    <script>
        let currentPartnerId = null;
        
        // Load conversation when clicking a chat
        function loadConversation(partnerId) {
            currentPartnerId = partnerId;
            
            // Highlight active chat
            document.querySelectorAll('.chat-list-item').forEach(item => {
                item.classList.remove('active');
            });
            document.getElementById(`chat-${partnerId}`).classList.add('active');
            
            // Update header
            const partnerName = document.getElementById(`chat-${partnerId}`).querySelector('strong').textContent;
            document.getElementById('currentChatHeader').innerHTML = `<h3>Chat with ${partnerName}</h3>`;
            
            // Enable input
            document.getElementById('messageInput').disabled = false;
            document.getElementById('sendButton').disabled = false;
            
            // Fetch messages
            fetchMessages();
        }
        
        // Fetch messages for current conversation
        function fetchMessages() {
            if (!currentPartnerId) return;
            
            fetch('chat-ui.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_messages&partner_id=${currentPartnerId}`
            })
            .then(response => response.json())
            .then(messages => {
                const container = document.getElementById('messageContainer');
                container.innerHTML = '';
                
                if (messages.length === 0) {
                    container.innerHTML = '<p>No messages yet. Start the conversation!</p>';
                    return;
                }
                
                // Mark messages as read
                const unreadIds = messages
                    .filter(m => m.receiver_id == <?= $current_user_id ?> && !m.is_read)
                    .map(m => m.id);
                
                if (unreadIds.length > 0) {
                    fetch('chat-ui.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=mark_read&message_ids=${JSON.stringify(unreadIds)}`
                    });
                }
                
                // Display messages
                messages.reverse().forEach(message => {
                    const isSent = message.sender_id == <?= $current_user_id ?>;
                    const messageDiv = document.createElement('div');
                    messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
                    messageDiv.innerHTML = `
                        <div>${message.message}</div>
                        <small class="message-time">
                            ${new Date(message.created_at).toLocaleString()} 
                            ${isSent ? '✓✓' : ''}
                        </small>
                    `;
                    container.appendChild(messageDiv);
                });
                
                // Scroll to bottom
                container.scrollTop = container.scrollHeight;
            });
        }
        
        // Send message
        document.getElementById('sendButton').addEventListener('click', () => {
            const input = document.getElementById('messageInput');
            const message = input.value.trim();
            
            if (message && currentPartnerId) {
                fetch('chat-ui.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=send&receiver_id=${currentPartnerId}&message=${encodeURIComponent(message)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        input.value = '';
                        fetchMessages();
                    }
                });
            }
        });
        
        // Send on Enter key
        document.getElementById('messageInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                document.getElementById('sendButton').click();
            }
        });
        
        // Poll for new messages every 5 seconds
        setInterval(fetchMessages, 5000);
    </script>
</body>
</html>