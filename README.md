# Shaheen Blog Wizard - WordPress Plugin

AI-powered blog post creation wizard with masonry display and comprehensive admin dashboard for managing posts and credits.

## Features

- **4-Step Post Creation Wizard**
  - Step 1: Basic Information (Title, Category, Author, Date)
  - Step 2: Keywords Input (2-3 keywords for headline generation)
  - Step 3: AI Feature Selection (Headlines, Subtitle, Meta, Article, Summary)
  - Step 4: Review & Create

- **AI Content Generation**
  - Headlines (5 options, keyword-based) - 50 credits
  - Subtitle - 30 credits
  - Meta Description - 20 credits
  - Full Article (1500-2000 words) - 180 credits
  - Summary - 80 credits

- **Credit System**
  - Monthly credit limits (default: 20,000)
  - Zero-cost post field editing (title, excerpt, category, etc.)
  - Pay-per-feature for AI regeneration
  - Credit tracking and transaction history

- **Admin Dashboard**
  - Dashboard with stats and recent posts
  - Create Post wizard with step-by-step guidance
  - All Posts page with search and filtering
  - Settings page for API keys and costs
  - Credit usage tracking

## Installation

1. Place the `shaheen-blog-wizard` folder in `/wp-content/plugins/`
2. Activate the plugin from WordPress admin
3. Go to Blog Wizard → Settings
4. Enter your OpenAI API key
5. Adjust credit costs and limits as needed

## Configuration

### OpenAI API Key
- Get your API key from [OpenAI Dashboard](https://platform.openai.com/api-keys)
- Paste it in Settings page

### Credit Settings
- Monthly Credit Limit: Default 20,000 credits per user per month
- Individual feature costs are configurable in Settings

## Database Schema

### Tables Created

1. **wp_shaheen_posts** - Stores plugin post records
2. **wp_shaheen_post_meta** - Stores keywords and metadata
3. **wp_shaheen_credits** - Tracks monthly credit usage
4. **wp_shaheen_credit_transactions** - Audit trail for credit usage
5. **wp_shaheen_ai_features** - Stores generated AI content
6. **wp_shaheen_settings** - Plugin configuration

## REST API Endpoints

All endpoints require admin/editor capability and WP REST nonce.

### POST /shaheen-blog-wizard/v1/posts/create
Create a new post
```json
{
  "title": "My Post Title",
  "excerpt": "Post excerpt",
  "category_id": 1,
  "keywords": ["keyword1", "keyword2"],
  "image_url": "https://example.com/image.jpg"
}
```

### POST /shaheen-blog-wizard/v1/posts/{id}/update
Update post fields (zero-cost operation)
```json
{
  "title": "Updated Title",
  "excerpt": "Updated excerpt",
  "category_id": 2
}
```

### POST /shaheen-blog-wizard/v1/generate/{feature}
Generate AI content
```json
{
  "topic": "Blog post topic",
  "keywords": ["keyword1", "keyword2"],
  "post_id": 123,
  "headline": "Main headline (for subtitle/meta)",
  "content": "Article content (for summary)"
}
```

### GET /shaheen-blog-wizard/v1/credits/available
Get available credits for current user

### GET /shaheen-blog-wizard/v1/dashboard/stats
Get dashboard statistics

### GET /shaheen-blog-wizard/v1/posts
Get list of user's posts with pagination

## File Structure

```
shaheen-blog-wizard/
├── shaheen-blog-wizard.php          # Main plugin file
├── includes/
│   ├── class-plugin-loader.php      # Plugin initialization
│   ├── class-database.php           # Database setup & settings
│   ├── class-admin.php              # Admin panel setup
│   ├── class-api.php                # REST API endpoints
│   ├── class-openai.php             # OpenAI API integration
│   ├── class-credit-system.php      # Credit management
│   └── functions.php                # Helper functions
├── templates/
│   ├── create-post-wizard.php       # Post wizard page
│   ├── all-posts.php                # Posts list page
│   └── settings.php                 # Settings page
├── assets/
│   ├── css/
│   │   └── admin.css                # Admin styling
│   └── js/
│       └── admin.js                 # Admin JavaScript
└── README.md                        # This file
```

## Development Notes

### Phase 1: Core Plugin Setup ✅ COMPLETED
- Plugin structure and initialization
- Database schema and tables
- Credit system and management
- OpenAI API integration
- REST API endpoints
- Admin panel setup
- Post wizard (4-step form)
- Settings page
- Basic styling and JavaScript

### Phase 2: Frontend Blog Display (NEXT)
- Masonry grid layout
- Search and filtering
- Category filtering
- Load more functionality
- Featured images display
- Post preview cards

### Phase 3: Advanced Features
- Post editing UI with live preview
- AI feature regeneration
- Edit history tracking
- Plagiarism checking integration
- Article rewriting

### Phase 4: User Experience
- Better error handling
- Loading states
- Progress indicators
- Notification system
- Mobile optimization

## Support

For issues or questions, visit the plugin settings page or review the debug information in WordPress error logs.

## License

GPL-2.0+

## Author

Shaheen Education Family
https://shaheen.edu.bd
