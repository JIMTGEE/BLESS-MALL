<?php
/**
 * Bless Mall - Admin Portal Footer
 * Premium Admin Dashboard Footer
 * 
 * Developed by NerdSpring Software Engineering
 * https://nerd-spring.com
 */
?>
    </div><!-- Close admin-main -->
    
    <!-- ==================== ADMIN FOOTER ==================== -->
    <footer class="admin-footer">
        <div class="admin-footer-inner">
            <!-- Left Section - Copyright -->
            <div class="footer-left">
                <div class="footer-brand">
                    <i class="fas fa-shopping-bag footer-brand-icon"></i>
                    <span class="footer-copyright">
                        &copy; <?php echo date('Y'); ?> <strong>Bless Mall</strong>
                    </span>
                </div>
                <span class="footer-rights">All rights reserved.</span>
            </div>
            
            <!-- Center Section - Credits -->
            <div class="footer-center">
                <div class="footer-credits-wrapper">
                    <span class="footer-developed-by">Developed by</span>
                    <a href="https://nerd-spring.com" target="_blank" rel="noopener noreferrer" class="nerdspring-link" title="Visit NerdSpring Software Engineering">
                        <svg class="ns-logo" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <linearGradient id="nsGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" style="stop-color:#3B82F6;stop-opacity:1" />
                                    <stop offset="100%" style="stop-color:#8B5CF6;stop-opacity:1" />
                                </linearGradient>
                            </defs>
                            <path d="M12 2L2 7L12 12L22 7L12 2Z" fill="url(#nsGradient)" opacity="0.9"/>
                            <path d="M2 17L12 22L22 17" stroke="url(#nsGradient)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M2 12L12 17L22 12" stroke="url(#nsGradient)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="12" cy="12" r="2" fill="url(#nsGradient)" opacity="0.6"/>
                        </svg>
                        <span class="ns-text">NerdSpring</span>
                        <span class="ns-subtext">Software Engineering</span>
                    </a>
                </div>
                <span class="footer-separator">•</span>
                <span class="footer-version" title="Bless Mall Version">
                    <i class="fas fa-code-branch"></i> v1.0.0
                </span>
            </div>
            
            <!-- Right Section - Quick Links -->
            <div class="footer-right">
                <a href="https://nerd-spring.com" target="_blank" rel="noopener noreferrer" class="footer-link" title="Visit NerdSpring Website">
                    <i class="fas fa-globe"></i>
                    <span class="link-text">nerd-spring.com</span>
                </a>
                <a href="mailto:support@blessmallgh.com" class="footer-link" title="Email Support">
                    <i class="fas fa-envelope"></i>
                    <span class="link-text">Support</span>
                </a>
                <a href="https://nerd-spring.com/docs" target="_blank" rel="noopener noreferrer" class="footer-link" title="Documentation">
                    <i class="fas fa-book"></i>
                    <span class="link-text">Docs</span>
                </a>
                <button class="footer-link back-to-top" onclick="scrollToTop()" title="Back to Top">
                    <i class="fas fa-arrow-up"></i>
                </button>
            </div>
        </div>
        
        <!-- Bottom Bar -->
        <div class="footer-bottom-bar">
            <div class="footer-bottom-inner">
                <span class="footer-tagline">
                    <i class="fas fa-bolt"></i> Powered by cutting-edge e-commerce technology
                </span>
                <span class="footer-separator">•</span>
                <span class="footer-status" id="footerStatus">
                    <span class="status-dot online"></span> System Online
                </span>
            </div>
        </div>
    </footer>

    <style>
        /* ========== ADMIN FOOTER STYLES ========== */
        .admin-footer {
            background: linear-gradient(180deg, var(--admin-bg-light) 0%, var(--admin-bg) 100%);
            border-top: 1px solid var(--admin-border);
            margin-top: 2rem;
            position: relative;
            z-index: 10;
        }
        
        .admin-footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, 
                transparent 0%, 
                var(--admin-primary) 20%, 
                #8B5CF6 50%, 
                var(--admin-primary) 80%, 
                transparent 100%
            );
            opacity: 0.5;
        }
        
        .admin-footer-inner {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        
        /* Left Section */
        .footer-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .footer-brand {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .footer-brand-icon {
            color: var(--admin-primary);
            font-size: 0.9rem;
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-3px); }
        }
        
        .footer-copyright {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        .footer-copyright strong {
            color: var(--text-primary);
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        
        .footer-rights {
            font-size: 0.72rem;
            color: var(--text-muted);
            opacity: 0.8;
        }
        
        /* Center Section */
        .footer-center {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .footer-credits-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .footer-developed-by {
            font-size: 0.72rem;
            color: var(--text-muted);
            opacity: 0.9;
        }
        
        .nerdspring-link {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: var(--admin-primary);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            padding: 0.35rem 0.75rem;
            border-radius: var(--radius-full);
            background: var(--admin-primary-glow);
            border: 1px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .nerdspring-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transition: left 0.5s ease;
        }
        
        .nerdspring-link:hover::before {
            left: 100%;
        }
        
        .nerdspring-link:hover {
            color: #FFFFFF;
            background: var(--admin-primary);
            border-color: var(--admin-primary-light);
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
            text-decoration: none;
        }
        
        .ns-logo {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            transition: transform 0.3s ease;
        }
        
        .nerdspring-link:hover .ns-logo {
            transform: rotate(15deg) scale(1.1);
        }
        
        .ns-text {
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        
        .ns-subtext {
            font-size: 0.65rem;
            font-weight: 400;
            opacity: 0.8;
            display: inline;
        }
        
        .footer-separator {
            color: var(--admin-border-light);
            font-size: 0.6rem;
            opacity: 0.5;
        }
        
        .footer-version {
            font-family: var(--font-mono, 'Courier New', monospace);
            font-size: 0.7rem;
            color: var(--admin-primary-light);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.2rem 0.5rem;
            background: rgba(59, 130, 246, 0.05);
            border-radius: var(--radius-sm);
            cursor: help;
        }
        
        .footer-version i {
            font-size: 0.55rem;
            opacity: 0.7;
        }
        
        .footer-version:hover {
            background: rgba(59, 130, 246, 0.1);
        }
        
        /* Right Section */
        .footer-right {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .footer-link {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.75rem;
            transition: all 0.2s ease;
            padding: 0.4rem 0.65rem;
            border-radius: var(--radius-md);
            white-space: nowrap;
        }
        
        .footer-link:hover {
            color: var(--text-primary);
            background: var(--admin-surface-hover);
            text-decoration: none;
            transform: translateY(-1px);
        }
        
        .footer-link i {
            font-size: 0.7rem;
            transition: transform 0.2s ease;
        }
        
        .footer-link:hover i {
            transform: scale(1.15);
        }
        
        .back-to-top {
            background: var(--admin-primary-glow);
            border: 1px solid var(--admin-border);
            cursor: pointer;
            font-family: inherit;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .back-to-top:hover {
            background: var(--admin-primary);
            color: #FFFFFF;
            border-color: var(--admin-primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .back-to-top i {
            font-size: 0.75rem;
        }
        
        /* Bottom Bar */
        .footer-bottom-bar {
            border-top: 1px solid var(--admin-border-light);
            padding: 0.6rem 1.5rem;
        }
        
        .footer-bottom-inner {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .footer-tagline {
            font-size: 0.65rem;
            color: var(--text-muted);
            opacity: 0.7;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        
        .footer-tagline i {
            color: #F59E0B;
            font-size: 0.5rem;
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        
        .footer-status {
            font-size: 0.65rem;
            color: var(--text-muted);
            opacity: 0.7;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        
        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .status-dot.online {
            background: #10B981;
            box-shadow: 0 0 6px rgba(16, 185, 129, 0.6);
            animation: statusPulse 2s ease-in-out infinite;
        }
        
        @keyframes statusPulse {
            0%, 100% { box-shadow: 0 0 4px rgba(16, 185, 129, 0.4); }
            50% { box-shadow: 0 0 10px rgba(16, 185, 129, 0.8); }
        }
        
        /* Responsive Footer */
        @media (max-width: 1024px) {
            .ns-subtext {
                display: none;
            }
        }
        
        @media (max-width: 768px) {
            .admin-footer-inner {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
                padding: 1rem;
            }
            
            .footer-left,
            .footer-center,
            .footer-right {
                justify-content: center;
                width: 100%;
            }
            
            .footer-center {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .footer-credits-wrapper {
                flex-direction: column;
                gap: 0.4rem;
            }
            
            .footer-separator {
                display: none;
            }
            
            .footer-bottom-bar {
                padding: 0.5rem 1rem;
            }
            
            .footer-bottom-inner {
                flex-direction: column;
                gap: 0.25rem;
                text-align: center;
            }
        }
        
        @media (max-width: 480px) {
            .footer-link .link-text {
                display: none;
            }
            
            .footer-link {
                padding: 0.5rem;
            }
            
            .back-to-top {
                width: 28px;
                height: 28px;
            }
            
            .nerdspring-link {
                padding: 0.3rem 0.6rem;
            }
        }
    </style>

    <!-- Toast Notification Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <style>
        /* ========== TOAST NOTIFICATIONS ========== */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 8px;
            pointer-events: none;
        }
        
        .toast {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            color: var(--text-primary);
            padding: 12px 20px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            font-weight: 500;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            animation: slideInRight 0.3s ease;
            max-width: 420px;
            backdrop-filter: blur(12px);
            pointer-events: auto;
            font-family: var(--font-body, inherit);
        }
        
        .toast.success {
            border-left: 3px solid var(--admin-success, #10B981);
        }
        .toast.success i {
            color: var(--admin-success, #10B981);
        }
        
        .toast.error {
            border-left: 3px solid var(--admin-danger, #EF4444);
        }
        .toast.error i {
            color: var(--admin-danger, #EF4444);
        }
        
        .toast.warning {
            border-left: 3px solid var(--admin-warning, #F59E0B);
        }
        .toast.warning i {
            color: var(--admin-warning, #F59E0B);
        }
        
        .toast.info {
            border-left: 3px solid var(--admin-primary, #3B82F6);
        }
        .toast.info i {
            color: var(--admin-primary, #3B82F6);
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(120%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(120%);
                opacity: 0;
            }
        }
        
        @media (max-width: 480px) {
            .toast-container {
                bottom: 16px;
                right: 16px;
                left: 16px;
            }
            
            .toast {
                max-width: 100%;
            }
        }
    </style>

    <script>
        /**
         * Toast Notification System
         * @param {string} message - The message to display
         * @param {string} type - success | error | warning | info
         * @param {number} duration - Duration in milliseconds
         */
        function showToast(message, type = 'info', duration = 4000) {
            const container = document.getElementById('toastContainer');
            if (!container) return;
            
            const icons = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };
            
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <i class="fas ${icons[type] || icons.info}"></i>
                <span>${escapeHtml(message)}</span>
            `;
            
            // Add close button for long messages
            if (message.length > 100 || duration > 5000) {
                const closeBtn = document.createElement('button');
                closeBtn.innerHTML = '&times;';
                closeBtn.style.cssText = `
                    background: none;
                    border: none;
                    color: inherit;
                    font-size: 1.2rem;
                    cursor: pointer;
                    padding: 0;
                    margin-left: auto;
                    opacity: 0.6;
                    line-height: 1;
                `;
                closeBtn.onclick = () => removeToast(toast);
                toast.appendChild(closeBtn);
            }
            
            container.appendChild(toast);
            
            // Auto remove after duration
            const timeout = setTimeout(() => removeToast(toast), duration);
            toast.dataset.timeout = timeout;
            
            // Pause auto-remove on hover
            toast.addEventListener('mouseenter', () => {
                clearTimeout(parseInt(toast.dataset.timeout));
            });
            
            toast.addEventListener('mouseleave', () => {
                const newTimeout = setTimeout(() => removeToast(toast), 2000);
                toast.dataset.timeout = newTimeout;
            });
        }
        
        function removeToast(toast) {
            clearTimeout(parseInt(toast.dataset.timeout));
            toast.style.animation = 'slideOutRight 0.3s ease forwards';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 300);
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        /**
         * Scroll to top smoothly
         */
        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }
        
        /**
         * Initialize footer features
         */
        document.addEventListener('DOMContentLoaded', function() {
            const backToTopBtn = document.querySelector('.back-to-top');
            if (backToTopBtn) {
                // Show/hide back to top button based on scroll position
                window.addEventListener('scroll', function() {
                    if (window.scrollY > 300) {
                        backToTopBtn.style.opacity = '1';
                        backToTopBtn.style.visibility = 'visible';
                    } else {
                        backToTopBtn.style.opacity = '0.5';
                        backToTopBtn.style.visibility = 'visible';
                    }
                });
            }
            
            // Update system status periodically
            function updateSystemStatus() {
                const statusEl = document.getElementById('footerStatus');
                if (statusEl) {
                    const now = new Date();
                    const hours = now.getHours();
                    const dot = statusEl.querySelector('.status-dot');
                    
                    if (dot) {
                        // Simulate occasional maintenance mode (just for demo - remove in production)
                        const isMaintenance = false; // Set based on actual system status
                        
                        if (isMaintenance) {
                            dot.className = 'status-dot maintenance';
                            statusEl.innerHTML = '<span class="status-dot maintenance"></span> Maintenance';
                        } else {
                            dot.className = 'status-dot online';
                            statusEl.innerHTML = '<span class="status-dot online"></span> System Online';
                        }
                    }
                }
            }
            
            updateSystemStatus();
            setInterval(updateSystemStatus, 60000); // Update every minute
        });
    </script>

</body>
</html>