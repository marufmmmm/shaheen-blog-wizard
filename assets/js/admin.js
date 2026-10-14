/**
 * Shaheen Blog Wizard Admin JavaScript
 */

// Global wizard data
window.wizardState = {
    postData: {
        title: '',
        excerpt: '',
        category: '',
        author: '',
        date: '',
        image: '',
        keywords: [],
    },
    selectedFeatures: [],
    totalCost: 0,
    currentPost: null,
};

/**
 * Fetch available credits
 */
async function fetchAvailableCredits() {
    try {
        const response = await fetch(shaheenBlogWizard.apiUrl + '/credits/available', {
            method: 'GET',
            headers: {
                'X-WP-Nonce': shaheenBlogWizard.nonce,
            }
        });

        const data = await response.json();

        if (data.success) {
            return data.available_credits;
        }
    } catch (error) {
        console.error('Error fetching credits:', error);
    }

    return 0;
}

/**
 * Generate AI content
 */
async function generateAIContent(feature, params) {
    try {
        const response = await fetch(
            shaheenBlogWizard.apiUrl + '/generate/' + feature,
            {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': shaheenBlogWizard.nonce,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(params)
            }
        );

        const data = await response.json();

        if (response.ok && data.success) {
            return {
                success: true,
                data: data.data,
                creditsUsed: data.credits_used,
                remainingCredits: data.remaining_credits,
            };
        } else {
            return {
                success: false,
                message: data.message || 'Unknown error occurred',
            };
        }
    } catch (error) {
        console.error('Error generating content:', error);
        return {
            success: false,
            message: error.message,
        };
    }
}

/**
 * Create a post
 */
async function createPost(postData) {
    try {
        const response = await fetch(shaheenBlogWizard.apiUrl + '/posts/create', {
            method: 'POST',
            headers: {
                'X-WP-Nonce': shaheenBlogWizard.nonce,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(postData)
        });

        const data = await response.json();

        if (response.ok && data.success) {
            return {
                success: true,
                postId: data.post_id,
            };
        } else {
            return {
                success: false,
                message: data.message || 'Failed to create post',
            };
        }
    } catch (error) {
        console.error('Error creating post:', error);
        return {
            success: false,
            message: error.message,
        };
    }
}

/**
 * Update a post
 */
async function updatePost(postId, updates) {
    try {
        const response = await fetch(
            shaheenBlogWizard.apiUrl + '/posts/' + postId + '/update',
            {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': shaheenBlogWizard.nonce,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(updates)
            }
        );

        const data = await response.json();

        if (response.ok && data.success) {
            return {
                success: true,
            };
        } else {
            return {
                success: false,
                message: data.message || 'Failed to update post',
            };
        }
    } catch (error) {
        console.error('Error updating post:', error);
        return {
            success: false,
            message: error.message,
        };
    }
}

/**
 * Load dashboard stats
 */
async function loadDashboardStats() {
    try {
        const response = await fetch(shaheenBlogWizard.apiUrl + '/dashboard/stats', {
            method: 'GET',
            headers: {
                'X-WP-Nonce': shaheenBlogWizard.nonce,
            }
        });

        const data = await response.json();

        if (data.success) {
            // Update UI with stats
            const totalPostsEl = document.getElementById('total-posts');
            const creditBarEl = document.getElementById('credit-bar');
            const creditTextEl = document.getElementById('credit-text');
            const recentPostsEl = document.getElementById('recent-posts');

            if (totalPostsEl) {
                totalPostsEl.textContent = data.stats.total_posts;
            }

            if (creditBarEl && data.stats.credits) {
                const percentage = data.stats.credits.percentage_used || 0;
                creditBarEl.style.width = percentage + '%';
            }

            if (creditTextEl && data.stats.credits) {
                const remaining = data.stats.credits.remaining_credits || 0;
                const total = data.stats.credits.total_credits || 0;
                creditTextEl.textContent = remaining + ' / ' + total + ' credits';
            }

            if (recentPostsEl && data.recent_posts && data.recent_posts.length > 0) {
                recentPostsEl.innerHTML = data.recent_posts.map(post =>
                    '<li><strong>' + escapeHtml(post.title) + '</strong><br><small>' + new Date(post.created_at).toLocaleDateString() + '</small></li>'
                ).join('');
            }
        }
    } catch (error) {
        console.error('Error loading dashboard stats:', error);
    }
}

/**
 * Escape HTML
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Show toast notification
 */
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#4caf50' : '#f44336'};
        color: white;
        padding: 15px 20px;
        border-radius: 4px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        z-index: 9999;
        animation: slideIn 0.3s ease;
    `;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

/**
 * Calculate total cost of selected features
 */
function calculateTotalCost() {
    const checkboxes = document.querySelectorAll('.feature-checkbox:checked');
    let total = 0;
    const features = [];

    checkboxes.forEach(cb => {
        const cost = parseInt(cb.dataset.cost) || 0;
        const feature = cb.dataset.feature;
        total += cost;
        features.push(feature);
    });

    window.wizardState.selectedFeatures = features;
    window.wizardState.totalCost = total;

    const totalCostEl = document.getElementById('total-cost');
    if (totalCostEl) {
        totalCostEl.textContent = total;
    }

    return total;
}

/**
 * Initialize dashboard
 */
document.addEventListener('DOMContentLoaded', function() {
    loadDashboardStats();

    // Refresh stats every 30 seconds
    setInterval(loadDashboardStats, 30000);
});
