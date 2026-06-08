# Provado

Provado is a Laravel package for revenue protection and diagnostics in ecommerce applications.

## Installation during development

Add the package repository to your Laravel application's `composer.json`:

```json
"repositories": [
  {
    "type": "path",
    "url": "../provado",
    "options": {
      "symlink": true
    }
  }
]
```

Then install it:

```bash
composer require mquevedob/provado:dev-main
```

Publish the config file:

```bash
php artisan vendor:publish --tag=provado-config
```

## Health check

After installing the package, this route should respond:

```text
/provado/health
```
