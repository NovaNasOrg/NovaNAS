<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Context
This app is a Web NAS OS addon app. It aims to be a webapp installed on the OS (debian/ubuntu). It will control the disks using ZFS/EXT4, control docker apps etc. It is not made to run in a docker container.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5.3
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- @inertiajs/react - v2
- phpunit/phpunit (PHPUNIT) - v11
- tailwindcss - v4.1
- spatie/laravel-permission - v7

The UI framework in use is mantine v8

## Inertia
We're using Inertia with React for the frontend. You can use tailwind if you need CSS and @tabler/icons-react if you need icons.
You should prioritize Inertia features for backend communication and use fetch only when using inertia is too complicated or will create complex code.
Never run npm run build as npm run dev is always running.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.
- Use the `database-schema` tool to inspect table structure before writing migrations or models.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - `public function __construct(public GitHub $github) { }`
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<!-- Explicit Return Types and Method Params -->
```php
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
```

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.


=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `php npm run build` or ask the user to run `php npm run dev` or `php composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== boost/core rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.
- Use the `database-schema` tool to inspect table structure before writing migrations or models.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

# PHPUnit

Do not write any tests until explicitly asked.

# Context7
Always use Context7 MCP when you need library/API documentation, code generation, setup or configuration steps without me having to explicitly ask.

=== desktop_window_system ===

# Desktop Window System

This application uses a desktop-like window system similar to Synology OS/Qnap/Ugreen. Apps open in movable, resizable windows on a desktop.

## Architecture

The window system is built with React Context and consists of these key components:

- **WindowContext** - Manages window state (open, close, maximize, focus, move, resize, z-index)
- **DraggableWindow** - The actual window component with drag/resize functionality
- **DesktopLayout** - Main container combining header + desktop area + window management
- **DesktopIcons** - Desktop icons that open apps on single-click

## Creating a New App

To create a new app that runs in the window system, follow these steps:

### 1. Create the App Component

Create your app component in `resources/js/Components/Apps/`. Use Mantine components for the UI.

```jsx
// resources/js/Components/Apps/MyNewApp.jsx
import { Box, Text, Title } from '@mantine/core';

export function MyNewAppContent({ title, emoji }) {
    return (
        <Box style={{ padding: '24px', height: '100%' }}>
            <Title order={2}>{title}</Title>
            <Text>App content goes here...</Text>
        </Box>
    );
}
```

### 2. Register the App Component in DesktopLayout

Open `resources/js/Components/Desktop/DesktopLayout.jsx` and:

1. Import your new app component (if not already using a sample):
```jsx
import { SampleAppContent } from '../Apps/SampleApp';
// Or import your custom component:
// import { MyNewAppContent } from '../Apps/MyNewApp';
```

2. Add it to the `APP_COMPONENTS` object using the same `identifier` you will use in the database:
```jsx
const APP_COMPONENTS = {
    filemanager: () => <SampleAppContent title="File Manager" emoji="📁" />,
    settings: () => <SampleAppContent title="Settings" emoji="⚙️" />,
    terminal: () => <SampleAppContent title="Terminal" emoji="💻" />,
    docker: () => <SampleAppContent title="Docker" emoji="🐳" />,
    monitor: () => <SampleAppContent title="Monitor" emoji="📊" />,
    storage: () => <SampleAppContent title="Storage" emoji="💾" />,
    mynewapp: () => <SampleAppContent title="My New App" emoji="🚀" />,  // Add this line
    // Or use your custom component:
    // mynewapp: () => <MyNewAppContent />,
};
```

3. Add the icon to the `ICON_MAP` object for icon name mapping:
```jsx
const ICON_MAP = {
    IconFolder,
    IconSettings,
    IconTerminal2,
    IconBrandDocker,
    IconActivity,
    IconDisc,
    IconApps,  // Add your icon here
};
```

### 3. Add the App to the Database

Add your app to the `desktop_apps` table via a migration or by seeding. Update `database/migrations/2026_02_24_234837_create_desktop_apps_table.php`:

```php
$apps = [
    // ... existing apps
    [
        'identifier' => 'mynewapp',
        'name' => 'My New App',
        'description' => 'Description of my new app',
        'type' => 'component',  // or 'url' for external links
        'icon_type' => 'tabler',
        'icon_name' => 'IconApps',  // Must match a key in ICON_MAP
        'color' => '#8b5cf6',  // HEX color or named color (blue, green, etc.)
        'component_path' => 'MyNewApp',  // Used for reference
        'is_system' => true,
        'is_global' => true,
        'is_admin_only' => false,
    ],
];
```

### Database Schema

The `desktop_apps` table has these key fields:

| Field | Type | Description |
|-------|------|-------------|
| `identifier` | string | Unique key (e.g., 'filemanager', 'docker') |
| `name` | string | Display name |
| `type` | enum | 'component' or 'url' |
| `url` | string | URL if type is 'url' |
| `icon_type` | enum | 'tabler' or 'image' |
| `icon_name` | string | Icon component name (e.g., 'IconFolder') |
| `color` | string | HEX color or named color |
| `component_path` | string | Component identifier for reference |
| `is_system` | boolean | System app (cannot be deleted) |
| `is_global` | boolean | Visible to all users |
| `is_admin_only` | boolean | Only visible to admins |

### Available Icons

Use icons from `@tabler/icons-react`. Common ones include:
- `IconFolder` - File Manager
- `IconSettings` - Settings
- `IconTerminal2` - Terminal
- `IconBrandDocker` - Docker
- `IconActivity` - Monitor
- `IconDisc` - Storage
- `IconApps` - Generic app
- `IconCloud` - Cloud services
- `IconUsers` - User management
- `IconNetwork` - Network settings

### Window Features

Windows automatically support:
- **Drag** - Click and drag the title bar to move
- **Resize** - Drag from edges/corners to resize
- **Maximize** - Click the maximize button to fill the desktop area
- **Close** - Click the X button to close the window
- **Focus** - Clicking a window brings it to front (highest z-index)

### How It Works

1. Apps are stored in the `desktop_apps` database table
2. The frontend fetches apps from the backend via Inertia props
3. Icons are mapped from database `icon_name` to Tabler React components via `ICON_MAP`
4. Component rendering is handled by `APP_COMPONENTS` lookup
5. User-specific icon order is stored in `user_desktop_icons` table

</laravel-boost-guidelines>
