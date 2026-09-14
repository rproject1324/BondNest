/**
 * Post Status Management JavaScript
 * Handles visual indicators and notifications for post status changes
 */

document.addEventListener('DOMContentLoaded', function() {
    // Apply status indicators to all posts based on their status attribute
    initializePostStatusIndicators();
    
    // Set up real-time update listeners
    setupStatusChangeListeners();
    
    // Check for admin actions from other pages (sessionStorage)
    checkForAdminStatusChanges();
    
    // Check for already shown approval notifications
    checkApprovalHistory();
});

/**
 * Initialize all posts with appropriate status indicators
 */
function initializePostStatusIndicators() {
    const posts = document.querySelectorAll('.post-item');
    
    posts.forEach(post => {
        const postId = post.getAttribute('data-post-id');
        const status = post.getAttribute('data-status');
        const isCurrentUserPost = post.querySelector('.post-actions-menu') !== null;
        
        // Apply the status indicator based on post status
        applyStatusIndicator(post, status, isCurrentUserPost);
    });
}

/**
 * Apply a visual status indicator to a post
 * @param {HTMLElement} postElement - The post element
 * @param {string} status - The post status (approved, on-hold, deleted)
 * @param {boolean} isCurrentUserPost - Whether this post belongs to the current user
 */
function applyStatusIndicator(postElement, status, isCurrentUserPost) {
    // Remove any existing status classes and notifications
    postElement.classList.remove('post-approved', 'post-on-hold', 'post-deleted');
    const existingNotification = postElement.querySelector('.post-status-notification');
    if (existingNotification) {
        existingNotification.remove();
    }
    
    // Remove any existing status indicators
    const existingStatusDot = postElement.querySelector('.status-indicator');
    if (existingStatusDot) {
        existingStatusDot.remove();
    }
    
    // Remove any existing status badges
    const existingStatusBadge = postElement.querySelector('.status-badge');
    if (existingStatusBadge) {
        existingStatusBadge.remove();
    }
    
    // Don't show indicators for regular posts with status 'posted'
    if (status === 'posted' || !status) {
        return;
    }
    
    // Apply status-specific styling and notifications
    if (status === 'approved') {
        // Show-once is server-driven: PHP stamps data-fresh-approval="1" only on
        // the first render after an approval (consumed server-side on display),
        // so re-approvals behave exactly like first approvals.
        const isFreshApproval = postElement.getAttribute('data-fresh-approval') === '1';

        if (!isFreshApproval) {
            // Not a fresh approval: hide any approval indicators
            postElement.setAttribute('data-status', 'posted');
            return;
        }

        postElement.classList.add('post-approved');
        
        // Only show approval notification to the post owner temporarily
        if (isCurrentUserPost) {
            const notification = createStatusNotification('approved', 'This post has been reviewed and approved by BondNest administrators.');
            insertNotificationInPost(postElement, notification);
            
            // Add status indicators (dot and badge)
            const statusDot = addStatusDot(postElement, status);
            const statusBadge = addStatusBadge(postElement, status);
            
            // Remove the approval notification and indicators after 5 seconds
            setTimeout(() => {
                // Remove the notification with fade-out animation
                if (notification && notification.parentNode) {
                    notification.classList.add('notification-fade-out');
                    setTimeout(() => {
                        if (notification && notification.parentNode) {
                            notification.parentNode.removeChild(notification);
                        }
                    }, 500);
                }
                
                // Remove the status dot with fade-out animation
                if (statusDot && statusDot.parentNode) {
                    statusDot.classList.add('notification-fade-out');
                    setTimeout(() => {
                        if (statusDot && statusDot.parentNode) {
                            statusDot.parentNode.removeChild(statusDot);
                        }
                    }, 500);
                }
                
                // Remove the status badge with fade-out animation
                if (statusBadge && statusBadge.parentNode) {
                    statusBadge.classList.add('notification-fade-out');
                    setTimeout(() => {
                        if (statusBadge && statusBadge.parentNode) {
                            statusBadge.parentNode.removeChild(statusBadge);
                        }
                    }, 500);
                }
                
                // Remove the approved class from the post
                postElement.classList.remove('post-approved');

                // Update the post's data-status attribute to 'posted'
                postElement.setAttribute('data-status', 'posted');

                // Drop the fresh flag so this banner never shows again for this render
                // (server already consumed its show-once flag on display)
                postElement.removeAttribute('data-fresh-approval');
            }, 5000);
        }
    } 
    else if (status === 'on-hold') {
        postElement.classList.add('post-on-hold');
        
        // For on-hold posts, show different messages to owner vs others
        if (isCurrentUserPost) {
            const notification = createStatusNotification('on-hold', 'This post has been placed on hold by BondNest administrators. Please check your notifications for details.');
            insertNotificationInPost(postElement, notification);
        } else {
            const notification = createStatusNotification('on-hold', 'This post is currently under review by BondNest administrators.');
            insertNotificationInPost(postElement, notification);
        }
        
        // Add persistent status indicators for on-hold posts
        addStatusDot(postElement, status);
        addStatusBadge(postElement, status);
        
        // Remove the post from the shown_approvals list when put on hold
        // This allows the approval notification to be shown again if it gets approved later
        const postId = postElement.getAttribute('data-post-id');
        if (postId) {
            const shownApprovals = JSON.parse(localStorage.getItem('shown_approvals') || '[]');
            const updatedApprovals = shownApprovals.filter(id => id !== postId);
            localStorage.setItem('shown_approvals', JSON.stringify(updatedApprovals));
        }
    }
    else if (status === 'deleted') {
        // For deleted posts, add fade out animation and hide them
        postElement.classList.add('post-deleted');
        
        // Add fade out animation to remove the post
        setTimeout(() => {
            postElement.classList.add('post-fade-out');
            
            // Actually remove from DOM after animation completes
            setTimeout(() => {
                if (postElement.parentNode) {
                    postElement.parentNode.removeChild(postElement);
                }
            }, 2000); // Longer timeout to ensure users can read the message
        }, 1000); // Shorter time without notification
        
        // Also remove from shown_approvals list for completeness
        const postId = postElement.getAttribute('data-post-id');
        if (postId) {
            const shownApprovals = JSON.parse(localStorage.getItem('shown_approvals') || '[]');
            const updatedApprovals = shownApprovals.filter(id => id !== postId);
            localStorage.setItem('shown_approvals', JSON.stringify(updatedApprovals));
        }
    }
}

/**
 * Create a status notification element
 * @param {string} status - The post status
 * @param {string} message - The notification message
 * @returns {HTMLElement} - The notification element
 */
function createStatusNotification(status, message) {
    const notification = document.createElement('div');
    notification.className = `post-status-notification ${status}`;
    
    let icon = 'check-circle-fill';
    if (status === 'on-hold') {
        icon = 'exclamation-triangle-fill';
    } else if (status === 'deleted') {
        icon = 'trash-fill';
    }
    
    // For approved notifications, place the check icon inline with the first word of text
    if (status === 'approved') {
        // Split the message to get the first word
        const firstSpace = message.indexOf(' ');
        const firstWord = message.substring(0, firstSpace);
        const restOfMessage = message.substring(firstSpace);
        
        notification.innerHTML = `
            <div class="notification-content">
                <i class="bi bi-${icon}" style="color: #3a96b8; margin-right: 5px;"></i>
                ${message}
            </div>
        `;
    } else {
        // For other types of notifications, keep the original structure
        notification.innerHTML = `
            <div class="notification-icon">
                <i class="bi bi-${icon}"></i>
            </div>
            <div class="notification-content">
                ${message}
            </div>
        `;
    }
    
    return notification;
}

/**
 * Insert notification at the top of the post
 * @param {HTMLElement} postElement - The post element
 * @param {HTMLElement} notification - The notification element
 */
function insertNotificationInPost(postElement, notification) {
    // Insert after any existing pending notice, or at the beginning of the post
    const pendingNotice = postElement.querySelector('.post-pending-notice');
    
    if (pendingNotice) {
        pendingNotice.after(notification);
    } else {
        postElement.prepend(notification);
    }
}

/**
 * Add a colored status dot to the post header
 * @param {HTMLElement} postElement - The post element
 * @param {string} status - The post status
 * @returns {HTMLElement} - The created status dot element
 */
function addStatusDot(postElement, status) {
    const postMeta = postElement.querySelector('.post-meta');
    if (!postMeta) return null;
    
    // Remove any existing status indicator
    const existingIndicator = postMeta.querySelector('.status-indicator');
    if (existingIndicator) {
        existingIndicator.remove();
    }
    
    // Only add indicators for non-standard statuses
    if (status !== 'posted' && status) {
        const indicator = document.createElement('span');
        indicator.className = `status-indicator ${status}`;
        indicator.title = status === 'approved' ? 'Approved by admin' : 'On hold';
        
        // Insert at the beginning of post-meta
        postMeta.prepend(indicator);
        return indicator;
    }
    
    return null;
}

/**
 * Add a status badge to the post
 * @param {HTMLElement} postElement - The post element
 * @param {string} status - The post status
 * @returns {HTMLElement} - The created status badge element
 */
function addStatusBadge(postElement, status) {
    const postMeta = postElement.querySelector('.post-meta');
    if (!postMeta) return null;
    
    // Remove any existing status badge
    const existingBadge = postMeta.querySelector('.status-badge');
    if (existingBadge) {
        existingBadge.remove();
    }
    
    // Only add badge for non-standard statuses
    if (status !== 'posted' && status) {
        const timeAgo = postMeta.querySelector('.time-ago');
        
        // Create status badge
        const badge = document.createElement('span');
        badge.className = `status-badge ${status}`;
        badge.innerText = status.charAt(0).toUpperCase() + status.slice(1);
        
        // Insert after the time-ago element
        if (timeAgo && timeAgo.nextSibling) {
            postMeta.insertBefore(badge, timeAgo.nextSibling);
        } else {
            postMeta.appendChild(badge);
        }
        
        return badge;
    }
    
    return null;
}

/**
 * Set up listeners for real-time status changes
 */
function setupStatusChangeListeners() {
    // This would integrate with your real-time notification system
    // For now, we'll check if there's a URL parameter indicating a status change
    
    const urlParams = new URLSearchParams(window.location.search);
    const updatedPostId = urlParams.get('updated_post');
    const newStatus = urlParams.get('status');
    
    if (updatedPostId && newStatus) {
        const post = document.querySelector(`.post-item[data-post-id="${updatedPostId}"]`);
        if (post) {
            // Update the post's data-status attribute
            post.setAttribute('data-status', newStatus);
            
            // Apply the new status indicator
            const isCurrentUserPost = post.querySelector('.post-actions-menu') !== null;
            applyStatusIndicator(post, newStatus, isCurrentUserPost);
            
            // Show a toast notification
            showStatusChangeToast(updatedPostId, newStatus);
            
            // Clean the URL parameters
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }
}

/**
 * Check for admin status changes stored in sessionStorage
 */
function checkForAdminStatusChanges() {
    try {
        // Get stored status changes
        const statusChanges = JSON.parse(sessionStorage.getItem('post_status_changes') || '{}');
        
        // Check if we have any changes
        const changes = Object.keys(statusChanges);
        if (changes.length === 0) return;
        
        // Flag to track if we made any changes to the page
        let changesMade = false;
        
        // Process each changed post
        changes.forEach(postId => {
            const change = statusChanges[postId];
            const post = document.querySelector(`.post-item[data-post-id="${postId}"]`);
            
            if (post) {
                // Update the post status
                post.setAttribute('data-status', change.status);
                
                // Apply visual indicators
                const isCurrentUserPost = post.querySelector('.post-actions-menu') !== null;
                applyStatusIndicator(post, change.status, isCurrentUserPost);
                
                // Add highlight animation to call attention to the change
                post.classList.add('status-transition');
                
                setTimeout(() => {
                    post.classList.remove('status-transition');
                }, 2000);
                
                changesMade = true;
            }
        });
        
        // If we made changes, show a toast notification
        if (changesMade) {
            showStatusChangeToast('multiple', 'updated');
            
            // Clear the processed changes from sessionStorage
            sessionStorage.removeItem('post_status_changes');
        }
        
    } catch (error) {
        console.error('Error checking admin status changes:', error);
    }
}

/**
 * Show a toast notification for status changes
 * @param {string} postId - The post ID or 'multiple'
 * @param {string} status - The new status
 */
function showStatusChangeToast(postId, status) {
    // Skip toast notification for deleted posts since we have the bell icon
    if (status === 'deleted') {
        return;
    }
    
    let message = '';
    let type = 'info';
    
    if (status === 'approved') {
        message = 'Your post has been approved by administrators';
        type = 'success';
    } else if (status === 'on-hold') {
        message = 'Your post has been placed on hold by administrators';
        type = 'warning';
    } else if (status === 'updated' && postId === 'multiple') {
        message = 'Posts have been updated with administrator actions';
        type = 'info';
    }
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    // Create icon based on type
    let icon = 'info-circle-fill';
    if (type === 'success') icon = 'check-circle-fill';
    if (type === 'warning') icon = 'exclamation-triangle-fill';
    if (type === 'error') icon = 'x-circle-fill';
    
    // Set toast content
    toast.innerHTML = `
        <div class="toast-content">
            <i class="bi bi-${icon}"></i>
            <span>${message}</span>
        </div>
        <button class="toast-close">×</button>
    `;
    
    // Add to document
    document.body.appendChild(toast);
    
    // Show toast with animation
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);
    
    // Add close button functionality
    const closeBtn = toast.querySelector('.toast-close');
    closeBtn.addEventListener('click', () => {
        toast.classList.remove('show');
        setTimeout(() => {
            document.body.removeChild(toast);
        }, 300);
    });
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            if (document.body.contains(toast)) {
                document.body.removeChild(toast);
            }
        }, 300);
    }, 5000);
}

/**
 * One-time cleanup of the obsolete client-side approval history.
 * Show-once is now server-driven via data-fresh-approval.
 */
function checkApprovalHistory() {
    try {
        localStorage.removeItem('shown_approvals');
    } catch (error) {
        console.error('Error checking approval history:', error);
    }
} 