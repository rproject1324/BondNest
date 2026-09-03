/**
 * BondNest Admin Panel JavaScript
 * Handles all functionality for the admin dashboard
 */

document.addEventListener('DOMContentLoaded', function() {
    // Cache DOM elements
    const refreshPostsBtn = document.getElementById('refreshPostsBtn');
    const postsTableBody = document.getElementById('posts-table-body');
    const modal = document.getElementById('postModal');
    const closeModal = document.querySelector('.close-modal');
    const modalAvatarContainer = document.getElementById('modalAvatarContainer');
    const modalAuthor = document.getElementById('modalAuthor');
    const modalUsername = document.getElementById('modalUsername');
    const modalCreatedAt = document.getElementById('modalCreatedAt');
    const modalContent = document.getElementById('modalContent');
    const modalImageContainer = document.getElementById('modalImageContainer');
    const modalImage = document.getElementById('modalImage');
    const actionPostId = document.getElementById('actionPostId');
    const searchInput = document.querySelector('.search-box input');
    
    // Store all posts data
    let currentPosts = []; // Will be populated on page load

    // Initial load of posts data
    loadPostsData();
    
    /**
     * Functions for handling posts refresh
     */
    function loadPostsData() {
        // Get initial posts data from the hidden element
        try {
            const postsDataElement = document.getElementById('posts-data');
            if (postsDataElement) {
                currentPosts = JSON.parse(postsDataElement.textContent);
            }
        } catch (e) {
            console.error('Failed to load initial posts data:', e);
        }
        
        // Set up refresh button
        if (refreshPostsBtn) {
            refreshPostsBtn.addEventListener('click', function() {
                // Show spinner
                refreshPostsBtn.classList.add('refreshing');
                // Do full page reload instead of AJAX refresh to preserve all columns
                window.location.reload();
            });
        }
    }
    
    function refreshPosts(showLoading = true) {
        if (showLoading) {
            refreshPostsBtn.classList.add('refreshing');
        }
        
        fetch('get_recent_posts.php?t=' + new Date().getTime()) // Prevent caching
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Store the previous post IDs for comparison
                    const oldPostIds = currentPosts.map(p => Number(p.id));
                    
                    // Update current posts with the new data
                    currentPosts = data.posts;
                    
                    // Check if there are new posts
                    const newPosts = data.posts.filter(post => !oldPostIds.includes(Number(post.id)));
                    const hasNewPosts = newPosts.length > 0;
                    
                    // Log for debugging
                    console.log('Refresh check - Auto:', !showLoading, 'New posts:', hasNewPosts, 'Count:', newPosts.length);
                    
                    // Update the table
                    updatePostsTable(data.posts, hasNewPosts);
                    
                    // Always show notification if there are new posts (regardless of manual or auto refresh)
                    if (hasNewPosts) {
                        // Use a more visible toast for new posts
                        showToast(`${newPosts.length} new post(s) have been added`, 'success', 8000);
                        
                        // Play a notification sound for better visibility
                        try {
                            const audio = new Audio('notification.mp3');
                            audio.volume = 0.5;
                            audio.play().catch(e => console.log('Sound notification not played:', e));
                        } catch(e) {
                            console.log('Sound notification error:', e);
                        }
                    }
                } else {
                    console.error('Failed to fetch posts:', data.error);
                    if (showLoading) {
                        showToast('Failed to refresh posts', 'error');
                    }
                }
            })
            .catch(error => {
                console.error('Error fetching posts:', error);
                if (showLoading) {
                    showToast('Error refreshing posts', 'error');
                }
            })
            .finally(() => {
                if (showLoading) {
                    refreshPostsBtn.classList.remove('refreshing');
                }
            });
    }
    
    function updatePostsTable(posts, highlightNewRows = false) {
        if (!postsTableBody) return;
        
        // Build the HTML for the posts table
        const html = posts.map(post => {
            // Determine the appropriate status badge
            let statusBadge = '';
            if (post.status === 'on-hold') {
                statusBadge = `<span class="status-badge status-on-hold">
                                <i class="bi bi-pause-circle-fill"></i>
                                On Hold
                              </span>`;
            } else if (post.status === 'approved') {
                statusBadge = `<span class="status-badge status-approved">
                                <i class="bi bi-check-circle-fill"></i>
                                Approved
                              </span>`;
            } else if (post.status === 'posted') {
                statusBadge = `<span class="status-badge status-posted">
                                <i class="bi bi-file-post"></i>
                                Posted
                              </span>`;
            } else {
                statusBadge = `<span class="status-badge">
                                <i class="bi bi-clock"></i>
                                ${post.status || 'Unknown'}
                              </span>`;
            }
            
            // Handle image display
            const hasImage = post.image_path ? 'has-image' : 'no-image';
            const imageIcon = post.image_path ? 'image-fill' : 'image';
            
            // Format content preview
            const contentPreview = post.content ? 
                (post.content.length > 50 ? post.content.substring(0, 50) + '...' : post.content) : '';
            
            // Use a safe default for profile pictures
            const profilePic = post.profile_picture || './web-images/default_profile.png';
            
            // Determine if this row should be highlighted as new
            const highlightClass = highlightNewRows ? 'highlight-new-row' : '';
            
            return `
            <tr class="${highlightClass}" data-post-id="${post.id}">
                <td>${post.id}</td>
                <td>
                    <div class="user-cell">
                        <img src="${escapeHTML(profilePic)}" alt="User" class="user-avatar">
                        <div class="user-info">
                            <div class="user-name">${escapeHTML(post.first_name || '')} ${escapeHTML(post.last_name || '')}</div>
                            <div class="username">@${escapeHTML(post.username || '')}</div>
                        </div>
                    </div>
                </td>
                <td class="content-cell" title="${escapeHTML(post.content || '')}">
                    ${escapeHTML(contentPreview)}
                </td>
                <td class="image-cell">
                    <span class="${hasImage}">
                        <i class="bi bi-${imageIcon}"></i>
                    </span>
                </td>
                <td>${formatDate(post.created_at)}</td>
                <td>${statusBadge}</td>
                <td>
                    <div class="action-btns">
                        <button class="btn btn-view btn-sm view-btn" data-post-id="${post.id}">
                            <i class="bi bi-eye-fill"></i>
                            View
                        </button>
                        <button class="btn btn-warn btn-sm warn-btn" data-post-id="${post.id}">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            Warn
                        </button>
                    </div>
                </td>
            </tr>
            `;
        }).join('');
        
        // Update the table
        postsTableBody.innerHTML = html;
    }
    
    /**
     * Event delegation for table actions
     */
    // Handle clicks on the posts table
    if (postsTableBody) {
        postsTableBody.addEventListener('click', function(event) {
            // Find the closest button if a child element was clicked
            const button = event.target.closest('.view-btn, .warn-btn');
            if (!button) return; // Not a button click
            
            const postId = button.getAttribute('data-post-id');
            const action = button.classList.contains('warn-btn') ? 'warn' : null;
            
            if (postId) {
                openPostModal(postId, action);
            }
        });
    }
    
    /**
     * Modal functionality
     */
    // Open modal with post data
    function openPostModal(postId, action = null) {
        // First look for the post in our current data
        let post = findPostById(postId);
        
        if (!post) {
            // If not found, fetch it from the server
            showToast('Loading post data...', 'info');
            
            fetch(`get_post.php?id=${postId}&t=${new Date().getTime()}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.post) {
                        displayPostInModal(data.post, action);
                    } else {
                        showToast('Error: ' + (data.error || 'Post not found'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error fetching post:', error);
                    showToast('Failed to load post data', 'error');
                });
        } else {
            // We have the post data, display it
            displayPostInModal(post, action);
        }
    }
    
    // Find a post by ID in the currentPosts array
    function findPostById(id) {
        return currentPosts.find(p => Number(p.id) === Number(id));
    }
    
    // Display post data in the modal
    function displayPostInModal(post, action = null) {
        if (!post) return;
        
        // Set modal content - handle avatar with initials fallback
        if (modalAvatarContainer) {
            if (post.profile_picture) {
                modalAvatarContainer.innerHTML = '<img src="' + post.profile_picture + '" alt="User" style="width:100%;height:100%;object-fit:cover;">';
            } else {
                const fn = (post.first_name || '')[0] || '';
                const ln = (post.last_name || '')[0] || '';
                const n = (post.first_name || '') + (post.last_name || '');
                let h = 0;
                for (let i = 0; i < n.length; i++) { h = (h * 31 + n.charCodeAt(i)) & 0x7FFFFFFF; }
                const colors = ['#2B9E9E','#3CB5A6','#E67E22','#3498DB','#9B59B6','#E74C3C','#1ABC9C','#2C3E50'];
                const bg = colors[h % colors.length];
                modalAvatarContainer.innerHTML = '<div style="width:100%;height:100%;background:' + bg + ';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:18px;font-family:Poppins,sans-serif;">' + (fn + ln).toUpperCase() + '</div>';
            }
        }
        modalAuthor.textContent = `${post.first_name || ''} ${post.last_name || ''}`.trim();
        modalUsername.textContent = post.username || '';
        modalCreatedAt.textContent = formatDate(post.created_at);
        modalContent.textContent = post.content || '';
        actionPostId.value = post.id;
        
        // Update likes and comments counts
        const modalLikeCount = document.getElementById('modalLikeCount');
        const modalCommentCount = document.getElementById('modalCommentCount');
        const modalLikeIcon = document.getElementById('modalLikeIcon');
        const modalLikeText = document.getElementById('modalLikeText');
        const modalLikeButton = document.getElementById('modalLikeButton');
        
        if (modalLikeCount) modalLikeCount.textContent = post.likes || '0';
        if (modalCommentCount) modalCommentCount.textContent = post.comment_count || '0';
        
        // Set like button state
        if (modalLikeButton && modalLikeIcon && modalLikeText) {
            // Update the like button state
            const isLiked = post.user_has_liked == 1;
            const hasLikes = parseInt(post.likes) > 0;
            modalLikeButton.setAttribute('data-post-id', post.id);
            modalLikeButton.setAttribute('data-liked', isLiked ? 'true' : 'false');
            
            // Update icon and text based on if current user liked the post
            if (isLiked) {
                modalLikeIcon.className = 'bi bi-heart-fill';
                modalLikeText.textContent = 'Liked';
                modalLikeIcon.style.color = '#e63946';
            } else {
                // If post has likes from others but not current user
                if (hasLikes) {
                    modalLikeIcon.className = 'bi bi-heart-fill';
                    modalLikeIcon.style.color = '#e63946';
                } else {
                    modalLikeIcon.className = 'bi bi-heart';
                    modalLikeIcon.style.color = '';
                }
                modalLikeText.textContent = 'Like';
            }
        }
        
        // Handle image
        if (post.image_path) {
            modalImageContainer.style.display = 'block';
            modalImage.src = post.image_path;
        } else {
            modalImageContainer.style.display = 'none';
        }
        
        // Show modal
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
        // If opened from warn button, focus on warning action
        if (action === 'warn') {
            setTimeout(() => {
                const warnButton = document.querySelector('.action-btn.btn-warn');
                if (warnButton) warnButton.focus();
            }, 100);
        }
        
        // Record view action
        recordAction('view', post.id);
    }
    
    // Close modal
    if (closeModal) {
        closeModal.addEventListener('click', function() {
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        });
    }
    
    // Close modal on outside click
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        });
    }
    
    /**
     * Action buttons functionality
     */
    // Set up action buttons
    const actionButtons = document.querySelectorAll('.action-btn');
    if (actionButtons.length > 0) {
        actionButtons.forEach(button => {
            button.addEventListener('click', function() {
                const action = this.getAttribute('data-action');
                const postId = actionPostId.value;
                const comment = document.getElementById('actionComment').value;
                
                // Process the action
                recordAction(action, postId, comment);
            });
        });
    }
    
    // Record an admin action
    function recordAction(action, postId, comment = '') {
        // Skip AJAX for view actions
        if (action === 'view') return;
        
        // Create form data for request
        const formData = new FormData();
        formData.append('action', action);
        formData.append('post_id', postId);
        formData.append('comment', comment);
        
        // Require reason for warnings
        if (action === 'warn' && !comment.trim()) {
            showToast('Please provide a reason for the warning.', 'error');
            return;
        }
        
        // Show loading state on buttons
        const buttons = document.querySelectorAll('.action-btn');
        buttons.forEach(btn => {
            btn.disabled = true;
            btn.innerHTML = `<i class="bi bi-arrow-repeat spinning"></i> Processing...`;
        });
        
        // Determine the correct endpoint based on action
        const endpoint = action === 'warn' ? 'direct_warn.php' : 'admin.php';
        
        // For warning, add additional data
        if (action === 'warn') {
            formData.append('action', 'warn_post');
            formData.append('reason', comment);
            
            // For debugging, add a timestamp to avoid caching
            formData.append('_t', new Date().getTime());
        }
        
        // Display fetch details for debugging
        console.log(`Sending request to: ${endpoint}`);
        console.log('Form data content:', Object.fromEntries(formData.entries()));
        
        // Send the request
        fetch(endpoint, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers);
            
            // Check if response is OK
            if (!response.ok) {
                throw new Error(`Network response was not ok: ${response.status} ${response.statusText}`);
            }
            
            // Try to get response as text first for debugging
            return response.text().then(text => {
                console.log('Raw response:', text);
                try {
                    // Try to parse as JSON
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Failed to parse JSON:', e);
                    throw new Error(`Invalid JSON response: ${text.substring(0, 100)}...`);
                }
            });
        })
        .then(data => {
            console.log('Response data:', data);
            if (data.success) {
                // Handle success based on action
                switch(action) {
                    case 'delete':
                        showToast('Post deleted successfully', 'success');
                        break;
                    case 'warn':
                        showToast('Warning sent successfully', 'success');
                        // Trigger stats refresh to update Today's Actions count
                        if (typeof window.refreshAdminStats === 'function') {
                            console.log('Refreshing admin stats after warning');
                            window.refreshAdminStats();
                        } else {
                            // Fallback to trigger a custom event that refreshes stats
                            console.log('Dispatching postUpdated event to refresh stats');
                            document.dispatchEvent(new CustomEvent('postUpdated'));
                        }
                        break;
                    case 'approve':
                        showToast('Post approved successfully', 'success');
                        break;
                    case 'hold':
                        showToast('Post has been placed on-hold', 'success');
                        break;
                    default:
                        showToast('Action completed successfully', 'success');
                        break;
                }
                
                // Close the modal
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
                
                // Refresh posts after successful action (except for warnings)
                if (action !== 'warn') {
                    setTimeout(function() {
                        // Use full page reload instead of AJAX refresh to preserve all columns
                        window.location.reload();
                    }, 500);
                }
            } else {
                // Handle error with more details
                const errorMsg = data.message || 'Unknown error';
                console.error('Error response:', data);
                showToast('Error: ' + errorMsg, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('An error occurred. Please try again. Details: ' + error.message, 'error');
        })
        .finally(() => {
            // Reset buttons
            buttons.forEach(btn => {
                btn.disabled = false;
                if (btn.dataset.action === 'approve') {
                    btn.innerHTML = `<i class="bi bi-check-circle-fill"></i> Approve Post`;
                } else if (btn.dataset.action === 'warn') {
                    btn.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> Send Warning`;
                } else if (btn.dataset.action === 'delete') {
                    btn.innerHTML = `<i class="bi bi-trash-fill"></i> Delete Post`;
                } else if (btn.dataset.action === 'hold') {
                    btn.innerHTML = `<i class="bi bi-pause-circle-fill"></i> Hold Post`;
                }
            });
        });
    }
    
    /**
     * Toast notifications
     */
    function showToast(message, type = 'info', duration = 5000) {
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        // For new post notifications during auto-refresh, make it more visible
        if (type === 'success' && message.includes('new post')) {
            toast.classList.add('toast-important');
        }
        
        // Create icon based on type
        let icon = 'info-circle-fill';
        if (type === 'success') icon = 'check-circle-fill';
        if (type === 'error') icon = 'exclamation-triangle-fill';
        if (type === 'warning') icon = 'exclamation-circle-fill';
        
        // Set toast content
        toast.innerHTML = `
            <div class="toast-content">
                <i class="bi bi-${icon}"></i>
                <span>${message}</span>
            </div>
            <button class="toast-close">×</button>
        `;
        
        // Remove any existing toasts with the same message (to prevent duplicates)
        document.querySelectorAll('.toast').forEach(existingToast => {
            if (existingToast.textContent.trim() === message.trim()) {
                existingToast.remove();
            }
        });
        
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
                if (document.body.contains(toast)) {
                    document.body.removeChild(toast);
                }
            }, 300);
        });
        
        // Auto remove after specified duration
        setTimeout(() => {
            if (document.body.contains(toast)) {
                toast.classList.remove('show');
                setTimeout(() => {
                    if (document.body.contains(toast)) {
                        document.body.removeChild(toast);
                    }
                }, 300);
            }
        }, duration);
    }
    
    /**
     * Utility functions
     */
    // Format date for display
    function formatDate(dateString) {
        try {
            let dStr = dateString;
            if (dStr && !dStr.includes('Z') && !dStr.includes('+') && !dStr.match(/T.*[+-]/)) {
                dStr = dStr.replace(' ', 'T') + 'Z';
            }
            const date = new Date(dStr);
            const options = { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                timeZone: 'Asia/Manila'
            };
            return date.toLocaleDateString('en-US', options);
        } catch (e) {
            return dateString;
        }
    }
    
    // Format time as relative (e.g., "2 hours ago")
    function timeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const secondsPast = (now.getTime() - date.getTime()) / 1000;

        if (secondsPast < 60) {
            return `${Math.floor(secondsPast)} seconds ago`;
        }
        if (secondsPast < 3600) {
            return `${Math.floor(secondsPast / 60)} minutes ago`;
        }
        if (secondsPast < 86400) {
            return `${Math.floor(secondsPast / 3600)} hours ago`;
        }
        if (secondsPast < 604800) {
            return `${Math.floor(secondsPast / 86400)} days ago`;
        }
        if (secondsPast < 2419200) {
            return `${Math.floor(secondsPast / 604800)} weeks ago`;
        }
        if (secondsPast < 29030400) {
            return `${Math.floor(secondsPast / 2419200)} months ago`;
        }
        return `${Math.floor(secondsPast / 29030400)} years ago`;
    }
    
    // Escape HTML to prevent XSS
    function escapeHTML(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    
    // Search functionality
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('.post-table tbody tr');
            
            rows.forEach(row => {
                const content = row.querySelector('.content-cell').textContent.toLowerCase();
                const username = row.querySelector('.username').textContent.toLowerCase();
                const name = row.querySelector('.user-name').textContent.toLowerCase();
                
                if (content.includes(searchTerm) || username.includes(searchTerm) || name.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
}); 