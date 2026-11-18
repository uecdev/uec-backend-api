<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>API reference · UEC Admin</title>
    <link rel="icon" type="image/png" href="{{ asset('uec-logo.png') }}">
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css" integrity="sha384-wxLW6kwyHktdDGr6Pv1zgm/VGJh99lfUbzSn6HNHBENZlCN7W602k9VkGdxuFvPn" crossorigin="anonymous">
    <style>body { margin: 0; background: #f7f5ef; } .topbar { display: none; } .uec-bar { display: flex; align-items: center; gap: 12px; padding: 12px 20px; background: #173a2d; color: #fff; font: 700 15px system-ui, sans-serif; } .uec-bar a { color: #d6e7de; margin-left: auto; font-weight: 600; text-decoration: none; }</style>
</head>
<body>
<div class="uec-bar">
    <img src="{{ asset('uec-logo.png') }}" alt="" width="28" height="28" style="border-radius: 50%; background: #fff">
    UEC Mobile API — v1
    <a href="{{ route('admin.dashboard') }}">← Back to dashboard</a>
</div>
<div id="swagger"></div>
<script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js" integrity="sha384-wmyclcVGX/WhUkdkATwhaK1X1JtiNrr2EoYJ+diV3vj4v6OC5yCeSu+yW13SYJep" crossorigin="anonymous"></script>
<script>
    window.ui = SwaggerUIBundle({ url: @js($specUrl), dom_id: '#swagger', deepLinking: true, docExpansion: 'none', defaultModelsExpandDepth: 0, tryItOutEnabled: false });
</script>
</body>
</html>
