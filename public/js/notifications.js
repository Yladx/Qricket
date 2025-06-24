class NotificationHandler {
    constructor() {
        this.socket = null;
        this.notificationContainer = null;
        this.setupNotificationContainer();
        this.initializeSocket();
    }

    setupNotificationContainer() {
        // Create notification container if it doesn't exist
        if (!document.getElementById('notification-container')) {
            this.notificationContainer = document.createElement('div');
            this.notificationContainer.id = 'notification-container';
            this.notificationContainer.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 9999;
                display: flex;
                flex-direction: column;
                gap: 10px;
            `;
            document.body.appendChild(this.notificationContainer);
        } else {
            this.notificationContainer = document.getElementById('notification-container');
        }
    }

    initializeSocket() {
        // Initialize Socket.io
        this.socket = io(window.location.origin, {
            auth: {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            }
        });

        // Listen for connection
        this.socket.on('connect', () => {
            console.log('Connected to notification server');
        });

        // Listen for notifications
        this.socket.on('notification', (data) => {
            this.showNotification(data.message, data.type);
        });

        // Handle connection errors
        this.socket.on('connect_error', (error) => {
            console.error('Socket connection error:', error);
            this.showNotification('Connection to notification server lost', 'error');
        });

        // Handle reconnection
        this.socket.on('reconnect', (attemptNumber) => {
            this.showNotification('Reconnected to notification server', 'success');
        });
    }

    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        
        // Set styles based on type
        const styles = {
            info: {
                backgroundColor: '#2196F3',
                color: 'white'
            },
            success: {
                backgroundColor: '#4CAF50',
                color: 'white'
            },
            warning: {
                backgroundColor: '#FFC107',
                color: 'black'
            },
            error: {
                backgroundColor: '#F44336',
                color: 'white'
            }
        };

        const typeStyle = styles[type] || styles.info;

        notification.style.cssText = `
            padding: 15px 20px;
            border-radius: 4px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            animation: slideIn 0.3s ease-out;
            background-color: ${typeStyle.backgroundColor};
            color: ${typeStyle.color};
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-width: 300px;
            max-width: 400px;
        `;

        // Create message container
        const messageContainer = document.createElement('div');
        messageContainer.style.cssText = `
            display: flex;
            align-items: center;
            gap: 10px;
        `;

        // Add icon based on type
        const icon = document.createElement('span');
        icon.className = 'notification-icon';
        icon.innerHTML = this.getIconForType(type);
        messageContainer.appendChild(icon);

        // Add message
        const messageText = document.createElement('span');
        messageText.textContent = message;
        messageContainer.appendChild(messageText);

        // Add close button
        const closeButton = document.createElement('button');
        closeButton.innerHTML = '×';
        closeButton.style.cssText = `
            background: none;
            border: none;
            color: inherit;
            font-size: 20px;
            cursor: pointer;
            padding: 0 5px;
            opacity: 0.7;
            transition: opacity 0.2s;
        `;
        closeButton.onmouseover = () => closeButton.style.opacity = '1';
        closeButton.onmouseout = () => closeButton.style.opacity = '0.7';
        closeButton.onclick = () => this.removeNotification(notification);

        // Add elements to notification
        notification.appendChild(messageContainer);
        notification.appendChild(closeButton);

        // Add to container
        this.notificationContainer.appendChild(notification);

        // Add animation styles if not already present
        if (!document.getElementById('notification-styles')) {
            const style = document.createElement('style');
            style.id = 'notification-styles';
            style.textContent = `
                @keyframes slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes slideOut {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
                .notification-icon {
                    font-size: 20px;
                }
            `;
            document.head.appendChild(style);
        }

        // Auto remove after 5 seconds
        setTimeout(() => this.removeNotification(notification), 5000);
    }

    removeNotification(notification) {
        notification.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => notification.remove(), 300);
    }

    getIconForType(type) {
        const icons = {
            info: 'ℹ️',
            success: '✅',
            warning: '⚠️',
            error: '❌'
        };
        return icons[type] || icons.info;
    }
}

// Initialize notification handler when document is ready
document.addEventListener('DOMContentLoaded', () => {
    window.notificationHandler = new NotificationHandler();
}); 