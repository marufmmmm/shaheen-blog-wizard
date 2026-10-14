<?php
/**
 * All Posts Template
 */
?>

<div class="wrap shaheen-blog-wizard-page">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div class="posts-toolbar">
        <div class="search-box">
            <input type="text" id="post-search" placeholder="Search posts..." class="form-control">
        </div>
        <div class="filter-box">
            <select id="post-status" class="form-control">
                <option value="">All Status</option>
                <option value="draft">Draft</option>
                <option value="published">Published</option>
                <option value="archived">Archived</option>
            </select>
        </div>
        <a href="<?php echo esc_url(admin_url('admin.php?page=shaheen-blog-wizard-create')); ?>" class="button button-primary">Create New Post</a>
    </div>

    <table class="wp-list-table widefat fixed striped posts">
        <thead>
            <tr>
                <th width="30%">Title</th>
                <th width="15%">Status</th>
                <th width="20%">Category</th>
                <th width="15%">Created</th>
                <th width="20%">Actions</th>
            </tr>
        </thead>
        <tbody id="posts-list">
            <tr>
                <td colspan="5" style="text-align: center; padding: 20px;">Loading...</td>
            </tr>
        </tbody>
    </table>

    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <span class="displaying-num" id="post-count">0 items</span>
            <span class="pagination-links" id="pagination">
                <!-- Pagination will be rendered here -->
            </span>
        </div>
    </div>
</div>

<style>
.posts-toolbar {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.search-box, .filter-box {
    flex: 1;
    min-width: 200px;
}

.search-box input, .filter-box select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.wp-list-table {
    width: 100%;
    margin-top: 10px;
}

.action-button {
    padding: 4px 8px;
    margin: 2px;
    font-size: 12px;
    text-decoration: none;
}

.action-button.edit {
    color: #2271b1;
}

.action-button.delete {
    color: #dc3545;
}

.post-date {
    color: #666;
    font-size: 13px;
}

.status-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}

.status-badge.draft {
    background: #fff3cd;
    color: #856404;
}

.status-badge.published {
    background: #d4edda;
    color: #155724;
}

.status-badge.archived {
    background: #e2e3e5;
    color: #383d41;
}
</style>

<script>
let currentPage = 1;
const perPage = 10;

function loadPosts() {
    const search = document.getElementById('post-search').value;
    const status = document.getElementById('post-status').value;

    let url = shaheenBlogWizard.apiUrl + '/posts?page=' + currentPage + '&per_page=' + perPage;

    if (search) url += '&search=' + encodeURIComponent(search);
    if (status) url += '&status=' + encodeURIComponent(status);

    fetch(url, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': shaheenBlogWizard.nonce,
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            renderPosts(data.posts);
            renderPagination(data);
            document.getElementById('post-count').textContent = data.total + ' items';
        }
    })
    .catch(err => console.error(err));
}

function renderPosts(posts) {
    const tbody = document.getElementById('posts-list');

    if (posts.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 20px;">No posts found</td></tr>';
        return;
    }

    tbody.innerHTML = posts.map(post => `
        <tr>
            <td><strong>${escapeHtml(post.title)}</strong></td>
            <td><span class="status-badge ${post.status}">${post.status}</span></td>
            <td>${post.category_id || '-'}</td>
            <td><span class="post-date">${new Date(post.created_at).toLocaleDateString()}</span></td>
            <td>
                <a href="#" class="action-button edit" onclick="editPost(${post.id}); return false;">Edit</a>
                <a href="#" class="action-button delete" onclick="deletePost(${post.id}); return false;">Delete</a>
            </td>
        </tr>
    `).join('');
}

function renderPagination(data) {
    const pages = data.total_pages;
    let html = '';

    if (data.page > 1) {
        html += `<a class="prev-page button" onclick="currentPage--; loadPosts();">← Previous</a>`;
    }

    html += `<span class="paging-input">Page ${data.page} of ${pages}</span>`;

    if (data.page < pages) {
        html += `<a class="next-page button" onclick="currentPage++; loadPosts();">Next →</a>`;
    }

    document.getElementById('pagination').innerHTML = html;
}

function editPost(postId) {
    // Implementation will open edit modal or page
    console.log('Edit post:', postId);
}

function deletePost(postId) {
    if (confirm('Are you sure you want to delete this post?')) {
        // Implementation will delete the post via API
        console.log('Delete post:', postId);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    loadPosts();

    document.getElementById('post-search').addEventListener('input', () => {
        currentPage = 1;
        loadPosts();
    });

    document.getElementById('post-status').addEventListener('change', () => {
        currentPage = 1;
        loadPosts();
    });
});
</script>
