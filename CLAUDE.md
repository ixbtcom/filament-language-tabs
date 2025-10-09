# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Обзор проекта

Это Laravel-пакет для FilamentPHP, который добавляет поддержку многоязычных полей с группировкой их в табы. Пакет работает с `spatie/laravel-translatable` для хранения переводов в JSON-формате.

## Команды разработки

### Тестирование

```bash
# Запуск всех тестов (Pest + PHPStan)
composer test

# Только функциональные тесты
composer test:pest
# или
./vendor/bin/pest

# Параллельное выполнение тестов
./vendor/bin/pest --parallel

# Только статический анализ
composer test:phpstan
# или
vendor/bin/phpstan analyse
```

### Качество кода

```bash
# Автоматическое форматирование (Laravel Pint)
composer pint
# или
vendor/bin/pint

# Статический анализ (PHPStan level 4)
vendor/bin/phpstan analyse
```

### Публикация ресурсов

```bash
# Публикация конфигурации
php artisan vendor:publish --tag="filament-language-tabs-config"

# Публикация views
php artisan vendor:publish --tag="filament-language-tabs-views"
```

## Архитектура компонента

### Основной компонент: LanguageTabs

**Расположение**: `src/Forms/Components/LanguageTabs.php`

Компонент наследуется от `Filament\Schemas\Components\Component` и использует `InteractsWithForms`.

**Ключевые методы:**

- `schema()` - создает табы для каждой локали, клонируя поля для каждого языка
- `tabfields()` - клонирует компоненты для конкретной локали, устанавливает `name` как `{base}_{locale}` и `statePath` как `{base}.{locale}`
- `prepareFieldForLocale()` - настраивает хуки `afterStateHydrated` и `afterStateUpdated` для синхронизации состояния с JSON-переводами
- `normaliseAttributeState()` - нормализует состояние атрибута из модели Spatie Translatable в структуру `['locale' => 'value']`

**Логика работы:**

1. Компонент получает массив базовых полей через `make([...])`
2. Для каждой локали из конфига создается отдельный Tab
3. Каждое поле клонируется и настраивается для работы с JSON-переводами через statePath
4. При гидратации данных из модели вызывается `getTranslations()` и данные распределяются по полям
5. При обновлении полей данные собираются обратно в JSON-формат и сохраняются через livewire

### Конфигурация

**Файл**: `config/filament-language-tabs.php`

```php
// Языки, для которых создаются табы
'default_locales' => ['de', 'en', 'fr']

// Языки, для которых поля обязательны (required)
'required_locales' => ['de', 'en']
```

### Интеграция с Spatie Translatable

Модель должна использовать trait `HasTranslations`:

```php
use Spatie\Translatable\HasTranslations;

class Post extends Model {
    use HasTranslations;

    public $translatable = ['headline', 'body'];

    protected $casts = [
        'headline' => 'array',
        'body' => 'array',
    ];
}
```

Поля в базе данных должны иметь тип `json`.

## Совместимость

- **FilamentPHP**: v4.x (основная ветка)
- **PHP**: ^8.2|^8.3|^8.4
- **Laravel**: 11.*|12.*
- **Зависимости**: `filament/schemas` ^4.0, `spatie/laravel-package-tools` ^1.13.5

Для старых версий:
- FilamentPHP v3.x: ветка `v2.x`
- FilamentPHP v2.x: ветка `v1.x`

## CI/CD

Проект использует GitHub Actions для автоматизации:

- **fix-php-code-style-issues.yml** - автоматический Pint при каждом push
- **phpstan.yml** - статический анализ при изменении PHP-файлов
- **run-tests.yml** - Pest тесты
- **dependabot-auto-merge.yml** - автоматический merge для patch/minor обновлений

## Особенности реализации

### Клонирование полей

Компонент клонирует поля для каждой локали с помощью `clone $component`. Важно понимать, что это shallow clone - объекты внутри не дублируются глубоко.

### Нормализация атрибутов

Свойство `$normalisedAttributes` отслеживает уже нормализованные атрибуты, чтобы избежать повторной обработки. Это критично для производительности при множественных гидратациях.

### Определение базовой локали

Компонент пытается определить базовую локаль в следующем порядке:
1. Метод `$record->baseLocale()`
2. Свойство `$record->baseLocale`
3. Первая локаль из `default_locales`
4. Fallback на `config('app.locale')`

### Интеграция с Livewire

После обновления поля вызывается `refreshFormData([$attribute])` для обновления других зависимых полей, если метод доступен в livewire-компоненте.