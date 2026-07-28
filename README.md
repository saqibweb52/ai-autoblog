# AI Autoblog WordPress Plugin

## Installation

1. Upload the `ai-autoblog` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to AI Autoblog > Settings to configure your AI grounding endpoint
4. Add authors in AI Autoblog > Authors
5. Add keywords in AI Autoblog > Keywords
6. The system will automatically process keywords via cron (every minute)

## Configuration

### AI Grounding SystemYou need to configure an AI grounding endpoint that can:
- Research topics without using search APIs
- Provide structured facts, summaries, and insights
- Find free licensed images (CC0, CC BY, Wikimedia Commons)

### JSON Configuration Files

All configuration is stored in JSON files in `/wp-content/plugins/ai-autoblog/data/`:

- `system_prompts.json` - Global AI behavior and writing rules
- `blog_instructions.json` - SEO rules and content structure
- `authors.json` - Author tones and writing styles
- `keywords.json` - Keyword queue (managed via admin)
- `external_links.json` - Whitelisted domains for external links
- `runtime_state.json` - System state (auto-managed)

## Manual Usage

1. Add keywords with assigned authors
2. Use "Manual Generate" to process immediately
3. Or wait for automatic cron processing (every minute)

## Requirements

- WordPress 5.0+
- PHP 7.4+
- External AI grounding system API

## Customization

You can easily modify:
- Author writing styles in `data/authors.json`
- SEO rules in `data/blog_instructions.json`
- AI prompts in `data/system_prompts.json`
- External link whitelist in `data/external_links.json`

## Support

For issues or customization, modify the modular PHP classes in `/includes/`.