# Shaheen Blog (WordPress plugin)

Plugin folder: `shaheen-blog/` — zip it (or use `shaheen-blog.zip`) and upload via Plugins → Add New → Upload.

- Blog page built from the original design (`index.html`), shortcode `[shaheen_blog]`, auto-created `/blog/` page
- Single post design + 6 demo posts (removable in Settings)
- 5-step AI post wizard (OpenAI; 2–3 keywords required): headlines 50, subtitle 30, meta 20, article 180, summary 80 credits
- Monthly credits (20,000), reset on subscription date; hook `shaheen_blog_set_subscription()` for payment code later
- Editing posts is free; only AI buttons cost credits
- Admin: dashboard, posts, categories, settings
