# Webhook Plugin Documentation

## Setup
1. Activate the plugin
2. Go to Settings → Webhook to configure:
   - Set an authentication key
   - Customize endpoint URL (default: /wp-json/webhook/v1/)

## API Endpoints


### Authentication
```bash
curl -X POST \
  -H "X-Auth-Key: YOUR_KEY" \
  https://yoursite.com/wp-json/webhook/v1/auth

### Upload Media
```bash
curl -X POST \
  -H "X-Auth-Key: YOUR_KEY" \
  -F "file=@image.jpg" \
  https://yoursite.com/wp-json/webhook/v1/upload
```

### Create Post
```bash
curl -X POST \
  -H "X-Auth-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "New Post",
    "content": "Post content",
    "status": "draft",
    "author": 1,
    "excerpt": "Post summary",
    "slug": "custom-url",
    "categories": ["News"],
    "tags": ["api", "webhook"]
  }' \
  https://yoursite.com/wp-json/webhook/v1/create-post
```

## Response Formats

### Media
```json
{
  "success": true,
  "data": {
    "mediaId": 123,
    "url": "https://yoursite.com/wp-content/uploads/image.jpg"
  }
}
```

### Post
```json
{
  "success": true,
  "data": {
    "id": 456,
    "url": "https://yoursite.com/custom-url/",
    "editUrl": "https://yoursite.com/wp-admin/post.php?post=456&action=edit"
  }
}
```

### Authentication Failure
```json
{
  "success": false,
  "error": "Invalid authentication"
}
