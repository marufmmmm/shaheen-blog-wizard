<?php
/**
 * Create Post Wizard Template
 */
?>

<div class="wrap shaheen-blog-wizard-page">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div class="wizard-container">
        <!-- Progress Indicator -->
        <div class="wizard-progress">
            <div class="progress-step" data-step="1"><span>1</span> Basic Info</div>
            <div class="progress-step" data-step="2"><span>2</span> Keywords</div>
            <div class="progress-step" data-step="3"><span>3</span> AI Features</div>
            <div class="progress-step" data-step="4"><span>4</span> Review</div>
        </div>

        <!-- Step 1: Basic Information -->
        <div class="wizard-page" data-page="1">
            <h2>Step 1: Basic Information</h2>

            <div class="form-group">
                <label for="post-title">Post Title *</label>
                <input type="text" id="post-title" class="form-control" placeholder="Enter your post title" required>
            </div>

            <div class="form-group">
                <label for="post-category">Category</label>
                <select id="post-category" class="form-control">
                    <option value="">Select a category</option>
                    <?php
                    $categories = get_categories(array('taxonomy' => 'category'));
                    foreach ($categories as $category) {
                        echo '<option value="' . esc_attr($category->term_id) . '">' . esc_html($category->name) . '</option>';
                    }
                    ?>
                </select>
            </div>

            <div class="form-group">
                <label for="post-author">Author</label>
                <select id="post-author" class="form-control">
                    <?php
                    $current_user = wp_get_current_user();
                    echo '<option value="' . esc_attr($current_user->ID) . '" selected>' . esc_html($current_user->display_name) . '</option>';
                    ?>
                </select>
            </div>

            <div class="form-group">
                <label for="post-date">Publication Date</label>
                <input type="datetime-local" id="post-date" class="form-control">
            </div>

            <div class="form-actions">
                <button type="button" class="button button-primary" onclick="wizardNext(1)">Next</button>
            </div>
        </div>

        <!-- Step 2: Keywords -->
        <div class="wizard-page" data-page="2" style="display: none;">
            <h2>Step 2: Keywords for Headlines</h2>

            <p class="description">Enter 2-3 keywords that will be used to generate relevant headlines.</p>

            <div class="keywords-input">
                <div class="keyword-field">
                    <label for="keyword-1">Keyword 1 *</label>
                    <input type="text" id="keyword-1" class="form-control keyword-input" placeholder="e.g., WordPress" required>
                </div>

                <div class="keyword-field">
                    <label for="keyword-2">Keyword 2 *</label>
                    <input type="text" id="keyword-2" class="form-control keyword-input" placeholder="e.g., SEO" required>
                </div>

                <div class="keyword-field">
                    <label for="keyword-3">Keyword 3 (Optional)</label>
                    <input type="text" id="keyword-3" class="form-control keyword-input" placeholder="e.g., Best Practices">
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="button" onclick="wizardPrev(2)">Back</button>
                <button type="button" class="button button-primary" onclick="wizardNext(2)">Next</button>
            </div>
        </div>

        <!-- Step 3: AI Features -->
        <div class="wizard-page" data-page="3" style="display: none;">
            <h2>Step 3: Select AI Features</h2>

            <div class="credits-info">
                <p>Available Credits: <strong id="available-credits">Loading...</strong></p>
                <p>Total Cost: <strong id="total-cost">0</strong> credits</p>
            </div>

            <div class="ai-features-grid">
                <?php
                $features = array(
                    'headlines' => array('label' => 'Headlines (5 options)', 'cost' => 50, 'description' => 'Generate 5 compelling headlines using your keywords'),
                    'subtitle' => array('label' => 'Subtitle', 'cost' => 30, 'description' => 'Create a subtitle to complement the headline'),
                    'meta_description' => array('label' => 'Meta Description', 'cost' => 20, 'description' => 'Write an SEO-friendly meta description'),
                    'full_article' => array('label' => 'Full Article', 'cost' => 180, 'description' => 'Generate a complete 1500-2000 word article'),
                    'summary' => array('label' => 'Summary', 'cost' => 80, 'description' => 'Create a concise summary of the article'),
                );

                foreach ($features as $feature => $data) {
                    ?>
                    <div class="ai-feature-card">
                        <input type="checkbox" id="feature-<?php echo esc_attr($feature); ?>" class="feature-checkbox" data-feature="<?php echo esc_attr($feature); ?>" data-cost="<?php echo esc_attr($data['cost']); ?>" onchange="calculateTotalCost()">
                        <label for="feature-<?php echo esc_attr($feature); ?>">
                            <h4><?php echo esc_html($data['label']); ?></h4>
                            <p><?php echo esc_html($data['description']); ?></p>
                            <span class="cost-badge"><?php echo esc_html($data['cost']); ?> credits</span>
                        </label>
                    </div>
                    <?php
                }
                ?>
            </div>

            <div class="form-actions">
                <button type="button" class="button" onclick="wizardPrev(3)">Back</button>
                <button type="button" class="button button-primary" onclick="wizardNext(3)">Next</button>
            </div>
        </div>

        <!-- Step 4: Review -->
        <div class="wizard-page" data-page="4" style="display: none;">
            <h2>Step 4: Review & Create</h2>

            <div class="review-section">
                <h3>Post Information</h3>
                <div class="review-item">
                    <label>Title:</label>
                    <span id="review-title">-</span>
                </div>
                <div class="review-item">
                    <label>Keywords:</label>
                    <span id="review-keywords">-</span>
                </div>
                <div class="review-item">
                    <label>Selected Features:</label>
                    <span id="review-features">-</span>
                </div>
                <div class="review-item">
                    <label>Total Cost:</label>
                    <span id="review-cost">0 credits</span>
                </div>
            </div>

            <div id="generation-status" style="display: none;">
                <p>Creating your post and generating content...</p>
                <div class="progress-bar">
                    <div class="progress-bar-fill" id="progress-fill"></div>
                </div>
            </div>

            <div class="form-actions" id="review-actions">
                <button type="button" class="button" onclick="wizardPrev(4)">Back</button>
                <button type="button" class="button button-primary" id="create-post-btn" onclick="createPost()">Create Post</button>
            </div>
        </div>
    </div>
</div>

<style>
.wizard-container {
    max-width: 600px;
    margin: 20px 0;
    background: white;
    border: 1px solid #ccc;
    border-radius: 4px;
    padding: 20px;
}

.wizard-progress {
    display: flex;
    gap: 10px;
    margin-bottom: 30px;
    justify-content: space-between;
}

.progress-step {
    flex: 1;
    padding: 10px;
    text-align: center;
    border: 2px solid #ddd;
    border-radius: 4px;
    background: #f5f5f5;
}

.progress-step.active {
    background: #2271b1;
    color: white;
    border-color: #2271b1;
}

.progress-step span {
    display: inline-block;
    width: 30px;
    height: 30px;
    line-height: 30px;
    background: rgba(255,255,255,0.3);
    border-radius: 50%;
    font-weight: bold;
    margin-right: 5px;
}

.wizard-page {
    animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    justify-content: flex-end;
}

.ai-features-grid {
    display: grid;
    gap: 15px;
    margin: 20px 0;
}

.ai-feature-card {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.ai-feature-card:hover {
    background: #f9f9f9;
    border-color: #2271b1;
}

.ai-feature-card input[type="checkbox"] {
    margin-top: 5px;
    cursor: pointer;
}

.ai-feature-card label {
    flex: 1;
    cursor: pointer;
    margin: 0;
}

.ai-feature-card h4 {
    margin: 0 0 5px 0;
}

.ai-feature-card p {
    margin: 0 0 10px 0;
    font-size: 13px;
    color: #666;
}

.cost-badge {
    display: inline-block;
    background: #2271b1;
    color: white;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
}

.review-item {
    display: flex;
    padding: 10px 0;
    border-bottom: 1px solid #eee;
}

.review-item label {
    font-weight: 500;
    min-width: 150px;
}

.keywords-input {
    display: grid;
    gap: 15px;
    margin: 20px 0;
}

.keyword-field label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}

.credits-info {
    background: #f0f6fc;
    padding: 15px;
    border-left: 4px solid #2271b1;
    margin-bottom: 20px;
}

.credits-info p {
    margin: 5px 0;
}
</style>

<script>
var wizardData = {
    title: '',
    category: '',
    author: '',
    date: '',
    keywords: [],
    selectedFeatures: [],
    totalCost: 0
};

function updateProgress(step) {
    document.querySelectorAll('.progress-step').forEach(el => {
        el.classList.remove('active');
    });
    document.querySelector('.progress-step[data-step="' + step + '"]').classList.add('active');
}

function wizardNext(currentStep) {
    if (currentStep === 1) {
        const title = document.getElementById('post-title').value;
        if (!title) {
            alert('Please enter a post title');
            return;
        }
        wizardData.title = title;
        wizardData.category = document.getElementById('post-category').value;
        wizardData.author = document.getElementById('post-author').value;
        wizardData.date = document.getElementById('post-date').value;
    }

    if (currentStep === 2) {
        const k1 = document.getElementById('keyword-1').value;
        const k2 = document.getElementById('keyword-2').value;
        const k3 = document.getElementById('keyword-3').value;

        if (!k1 || !k2) {
            alert('Please enter at least 2 keywords');
            return;
        }

        wizardData.keywords = [k1, k2];
        if (k3) wizardData.keywords.push(k3);
    }

    if (currentStep === 3) {
        // Will auto-validate features
    }

    if (currentStep === 4) {
        updateReviewPage();
    }

    showPage(currentStep + 1);
}

function wizardPrev(currentStep) {
    showPage(currentStep - 1);
}

function showPage(page) {
    document.querySelectorAll('.wizard-page').forEach(el => {
        el.style.display = 'none';
    });
    document.querySelector('.wizard-page[data-page="' + page + '"]').style.display = 'block';
    updateProgress(page);
}

function calculateTotalCost() {
    const checkboxes = document.querySelectorAll('.feature-checkbox:checked');
    let total = 0;
    wizardData.selectedFeatures = [];

    checkboxes.forEach(cb => {
        total += parseInt(cb.dataset.cost);
        wizardData.selectedFeatures.push(cb.dataset.feature);
    });

    wizardData.totalCost = total;
    document.getElementById('total-cost').textContent = total;
}

function updateReviewPage() {
    document.getElementById('review-title').textContent = wizardData.title;
    document.getElementById('review-keywords').textContent = wizardData.keywords.join(', ');
    document.getElementById('review-features').textContent = wizardData.selectedFeatures.join(', ') || 'None selected';
    document.getElementById('review-cost').textContent = wizardData.totalCost + ' credits';
}

function createPost() {
    // Implementation will use the REST API
    console.log('Creating post with data:', wizardData);
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    showPage(1);

    // Fetch available credits
    fetch(shaheenBlogWizard.apiUrl + '/credits/available', {
        method: 'GET',
        headers: {
            'X-WP-Nonce': shaheenBlogWizard.nonce,
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('available-credits').textContent = data.available_credits;
        }
    });
});
</script>
