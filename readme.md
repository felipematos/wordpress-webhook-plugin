# Simple Webhook Plugin for Wordpress Documentation

## Setup
1. Activate the plugin
2. Go to Settings → Webhook to configure:
   - Get an authentication key
   - Get endpoint URLs (default: /wp-json/webhook/v1/)
   - Configure webhook triggers for WordPress events

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

### Uploading Files to the Webhook

The webhook supports uploading files either directly or from an external URL.

### Direct File Upload
To upload a file directly, use the `file` parameter in the form data.

**Example cURL Command:**
```bash
curl -X POST "https://your-webhook-url.com/webhook/v1/upload" \
     -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
     -F "action=upload" \
     -F "file=@/path/to/your/image.png"
```

### Uploading from a URL
To upload a file from an external URL, use the `file_url` parameter.

**Example cURL Command:**
```bash
curl -X POST "https://your-webhook-url.com/webhook/v1/upload" \
     -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
     -F "action=upload" \
     -F "file_url=https://example.com/path/to/your/image.png"
```

Ensure that the URL is accessible and points directly to the file you wish to upload. The server will download the file from the URL and process it as if it were uploaded directly.

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
```

### Endpoint: get-post

**Description:** Retrieves all data for a specific post.

**Endpoint:** `POST /wp-json/webhook/v1/get-post`

```bash
curl -X POST \
  -H "X-Auth-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "postId": 123
  }' \
  https://yoursite.com/wp-json/webhook/v1/get-post
```


**Example Request Body:**
```json
{
  "postId": 123
}
```

**Example Response:**
```json
{
  "success": true,
  "ID": 123,
  "post_author": "1",
  "post_date": "2025-02-03 10:00:00",
  "post_title": "Sample Post",
  "post_content": "This is the post content.",
  "post_status": "publish",
  ...
}
```

## Webhook Triggers

The plugin can send webhook notifications when specific WordPress events occur. Each trigger can be configured with its own destination URL and custom headers.

### Available Triggers
1. New post Created
2. New post published
3. New comment received

1. **New Post Created**
   - Triggered when a new blog post is created
   - Payload includes: post ID, title, type, status, date, and author
   ```json
   {
     "event": "post_created",
     "post_id": 123,
     "post_title": "My New Post",
     "post_type": "post",
     "post_status": "draft",
     "post_date": "2025-02-03 16:52:31",
     "post_author": 1
   }
   ```

2. **Post Published**
   - Triggered when a blog post changes status to 'published'
   - Payload includes: post ID, title, type, date, author, and public URL
   ```json
   {
     "event": "post_published",
     "post_id": 123,
     "post_title": "My New Post",
     "post_type": "post",
     "post_date": "2025-02-03 16:52:31",
     "post_author": 1,
     "post_url": "https://yoursite.com/my-new-post"
   }
   ```

3. **New Comment**
   - Triggered when a new comment is received on any post
   - Payload includes: comment ID, post ID, author details, content, date, and status
   ```json
   {
     "event": "new_comment",
     "comment_id": 456,
     "comment_post_id": 123,
     "comment_author": "John Doe",
     "comment_author_email": "john@example.com",
     "comment_content": "Great post!",
     "comment_date": "2025-02-03 16:52:31",
     "comment_status": "1"
   }
   ```

### Configuring Triggers

1. Go to Settings → Webhook
2. In the Triggers section, locate the trigger you want to configure
3. Enable the trigger using the checkbox
4. Enter the destination URL where the webhook should send the POST request
5. (Optional) Click "Custom Headers" to add custom HTTP headers
   - Headers should be in JSON format, e.g.:
   ```json
   {
     "X-Auth-Key": "your-secret-key",
     "Custom-Header": "custom-value"
   }
   ```

### Notes
- All webhook requests are sent as HTTP POST
- The request body is JSON-encoded
- Default headers include:
  - `Content-Type: application/json`
  - `User-Agent: WordPress/[version]; [site-url]`
- Failed webhook attempts are logged and can be viewed in the Logs section

### Creator

Hi! I'm Felipe Matos, AI & technology & entrepreneurship expert, head of 10K Digital Consulting. I made this free plugin for Wordpress automation with love!

Check out my website for more tools, and services.

Contact me:

https://www.felipematos.net
https://10k.digital
https://x.com/felipematos
https://linkedin.com/in/felipematos
