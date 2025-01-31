# Webhook Plugin Documentation

## Setup
1. Activate the plugin
2. Go to Settings → Webhook to configure:
   - Get an authentication key
   - Get endpoint URLs (default: /wp-json/webhook/v1/)

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
    "tags": ["api", "webhook"],
    "featuredMediaId": 123
  }' \
  https://yoursite.com/wp-json/webhook/v1/create-post
```
- **title** (required): The title of the post.
- **content** (required): The content of the post.
- **status** (optional): The status of the post (e.g. "draft", "publish"). Defaults to "draft".
- **author** (optional): The ID of the user who will be the author of the post. Defaults to the current user.
- **excerpt** (optional): The excerpt of the post.
- **slug** (optional): The slug of the post.
- **categories** (optional): An array of category names or IDs.
- **tags** (optional): An array of tag names or IDs.
- **featuredMediaId** (optional): An integer representing the ID of a media item to be set as the featured media for the post. If the provided ID is invalid, an error will be returned.

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
