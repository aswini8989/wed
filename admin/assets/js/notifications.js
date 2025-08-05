// Check for new notifications every 30 seconds
function checkNotifications() {
    if (!document.getElementById('notificationCounter')) return;
    
    fetch('get-notifications.php?action=check')
        .then(response => response.json())
        .then(data => {
            if (data.new > 0) {
                updateNotificationCounter();
                
                // Show desktop notification
                if (Notification.permission === 'granted') {
                    new Notification('New Notification', {
                        body: `You have ${data.new} new notification(s)`,
                        icon: '/assets/images/logo.png'
                    });
                }
            }
        });
}

function updateNotificationCounter() {
    fetch('get-notifications.php?action=count')
        .then(response => response.json())
        .then(data => {
            const counter = document.getElementById('notificationCounter');
            if (counter) {
                counter.textContent = data.count > 0 ? data.count : '';
                counter.style.animation = 'pulse 0.5s 2';
                setTimeout(() => {
                    counter.style.animation = '';
                }, 1000);
            }
        });
}

// Request notification permission on page load
document.addEventListener('DOMContentLoaded', () => {
    if (window.Notification && Notification.permission !== 'denied') {
        Notification.requestPermission();
    }
    
    // Check every 30 seconds
    setInterval(checkNotifications, 30000);
    
    // Add pulse animation to CSS
    const style = document.createElement('style');
    style.textContent = `
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
    `;
    document.head.appendChild(style);
});