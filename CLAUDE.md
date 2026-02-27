# CLAUDE.md — Filament Language Tabs

Filament-пакет для мультиязычных полей с группировкой в табы. Интеграция со `spatie/laravel-translatable`.

## Совместимость

- **Filament**: 5.x (`filament/schemas` ^4.0)
- **PHP**: ^8.2|^8.3|^8.4
- **Laravel**: 11.*|12.*
- Старые версии: v2.x (Filament 3), v1.x (Filament 2)

## Команды

```bash
composer test              # Pest + PHPStan
composer test:pest         # Только тесты
composer test:phpstan      # Только статанализ (level 4)
composer pint              # Форматирование (Laravel Pint)

# Публикация
php artisan vendor:publish --tag="filament-language-tabs-config"
php artisan vendor:publish --tag="filament-language-tabs-views"
```

## Архитектура

### Основной компонент: LanguageTabs

**Файл:** `src/Forms/Components/LanguageTabs.php`

**Наследование:** `LanguageTabs extends Tabs` (Filament\Schemas\Components\Tabs), использует `InteractsWithForms`.

### 3 драйвера переводов

| Driver | statePath | Описание |
|--------|-----------|----------|
| `json` (default) | `attribute.locale` | Стандартный Spatie — переводы в JSON-колонке |
| `hybrid` | base locale в основной колонке, остальные в `storageColumn.locale.attribute` | Оптимизация: base locale без JSON-десериализации |
| `extra_only` | `storageColumn.locale.attribute` для всех локалей | Все переводы в отдельной колонке |

Driver определяется через `$model->translatable` массив или fallback на `'json'`.

### Ключевые методы

| Метод | Назначение |
|-------|-----------|
| `schema($components)` | Сохраняет translatableSchema |
| `getDefaultChildComponents()` | Генерирует Tab для каждой локали, клонирует поля через `getClone()` |
| `resolveStatePath()` | Настраивает statePath для локализованных полей |
| `resolveAttributeStatePath()` | Определяет путь в зависимости от driver'а |
| `resolveLocales()` | 4-уровневый алгоритм определения локалей |
| `resolveBaseLocale()` | 3-шаговый алгоритм определения базовой локали |
| `resolveStorageColumn()` | Кастомная колонка через `$model->translationStorageColumn()` |
| `resolveAttributeDefinition()` | Per-attribute конфигурация из `$model->translatable` |

**Важно:** используется `getClone()` (не `clone $component`).

### Алгоритм resolveLocales()

1. `$model->getTranslatableLocales()` (метод)
2. `$model->translatableLocales` (property)
3. `config('filament-language-tabs.default_locales')`
4. `[config('app.locale')]`

### Алгоритм resolveBaseLocale()

1. `$record->baseLocale()` (метод)
2. Первая локаль из `resolveLocales()`
3. `config('app.locale')`

## Конфигурация

**Файл:** `config/filament-language-tabs.php`

```php
'default_locales' => ['de', 'en', 'fr'],    // Локали по умолчанию
'required_locales' => ['de', 'en'],          // Обязательные локали
'locale_labels' => ['de' => 'Deutsch', ...], // Кастомные метки табов
```

## Интеграция с моделью

Модель должна использовать trait `HasTranslations` от Spatie и может определять:

```php
use Spatie\Translatable\HasTranslations;

class Post extends Model {
    use HasTranslations;

    public $translatable = ['headline', 'body'];
    // Или с per-attribute конфигурацией:
    // public $translatable = ['headline' => ['driver' => 'hybrid']];

    // Опциональные методы:
    public function getTranslatableLocales(): array { ... }
    public function translationStorageColumn(): string { return 'extra_translations'; }
    public function baseLocale(): string { return 'ru'; }
}
```

## CI/CD

GitHub Actions: fix-php-code-style-issues, phpstan, run-tests, dependabot-auto-merge.
