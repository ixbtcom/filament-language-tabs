# План: Переписать LanguageTabs с поддержкой драйверов

> **Дата**: 2025-10-10
> **Цель**: Создать новый LanguageTabs который наследуется от Tabs и поддерживает все драйверы
> **Подход**: Комбинация TranslatableTabs (Abdulmajeed) + поддержка драйверов (наш laravel-translatable)

---

## Архитектура

### Новый LanguageTabs

```php
class LanguageTabs extends Tabs  // ← Наследуется от Tabs, а не Component!
{
    use InteractsWithForms;

    protected array | Closure $schema;

    // Методы:
    // 1. make($schema) - статический конструктор
    // 2. schema($schema) - установка схемы
    // 3. getDefaultChildComponents() - создание табов (КЛЮЧЕВОЙ МЕТОД)
    // 4. resolveStatePath($attribute, $locale) - определение правильного statePath
    // 5. resolveLocales() - получение списка локалей
    // 6. resolveLocaleLabel($locale) - получение лейбла локали
}
```

### Как работает

```
[Filament рендерит форму]
  ↓
[Вызывает LanguageTabs::getDefaultChildComponents()]
  ↓
[Для каждой локали (ru, en, hy...):
    Для каждого компонента (title, blocks...):
        1. Клонируем компонент
        2. Определяем драйвер через resolveStatePath()
        3. Устанавливаем правильный statePath
        4. Добавляем в Tab
]
  ↓
[Возвращает массив Tab компонентов]
  ↓
[Filament рендерит Tabs с правильными statePath]
  ↓
[При переключении таба:
    Filament САМ гидрирует компоненты с правильным statePath
    Builder читает data.blocks.ru через $wire.get()
    EditorJS обновляется через Alpine $entangle
]
  ↓
[✅ Всё работает без JS событий!]
```

---

## Пошаговый план

### Шаг 1: Базовая структура класса

**Файл:** `src/Forms/Components/LanguageTabs.php`

```php
<?php

namespace Pixelpeter\FilamentLanguageTabs\Forms\Components;

use Closure;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class LanguageTabs extends Tabs
{
    use InteractsWithForms;

    /**
     * Схема компонентов для перевода
     */
    protected array | Closure $translatableSchema;

    /**
     * Создать новый LanguageTabs
     */
    public static function make(array | Closure | Schema $schema = []): static
    {
        $static = app(static::class);
        $static->translatableSchema($schema);
        $static->configure();

        return $static;
    }

    /**
     * Установить схему компонентов
     */
    public function translatableSchema(array | Closure | Schema $schema): static
    {
        $this->translatableSchema = $schema;

        return $this;
    }

    /**
     * Получить компоненты схемы
     */
    protected function getTranslatableComponents(): array
    {
        $schema = $this->evaluate($this->translatableSchema);

        if ($schema instanceof Schema) {
            return $schema->getComponents();
        }

        return is_array($schema) ? $schema : [];
    }
}
```

**Что делает:**
- Наследуется от `Tabs` (а не от `Component`!)
- Хранит схему компонентов в `$translatableSchema`
- Метод `make()` создает экземпляр
- Метод `translatableSchema()` устанавливает схему
- Метод `getTranslatableComponents()` возвращает массив компонентов

**Использование:**
```php
LanguageTabs::make([
    TextInput::make('title'),
    Textarea::make('description'),
    Builder::make('blocks'),
])
```

---

### Шаг 2: Создание табов (getDefaultChildComponents)

**Добавить метод:**

```php
/**
 * Создать табы для каждой локали
 */
public function getDefaultChildComponents(): array
{
    $components = $this->getTranslatableComponents();
    $locales = $this->resolveLocales();
    $tabs = [];

    foreach ($locales as $locale) {
        $fields = [];

        foreach ($components as $component) {
            $clone = $component->getClone();
            $attribute = $clone->getName();

            // Определяем правильный statePath в зависимости от драйвера
            $statePath = $this->resolveStatePath($attribute, $locale);

            // Устанавливаем name и statePath
            $clone
                ->name("{$attribute}_{$locale}")
                ->statePath($statePath);

            // Обрабатываем специальные компоненты
            if ($clone instanceof Builder) {
                $this->prepareBuilderForLocale($clone, $attribute, $locale);
            } elseif ($clone instanceof Repeater) {
                $this->prepareRepeaterForLocale($clone, $attribute, $locale);
            }

            $fields[] = $clone;
        }

        $tab = Tab::make($this->resolveLocaleLabel($locale))
            ->key("tab_{$locale}")
            ->schema($fields);

        $tabs[] = $tab;
    }

    return $tabs;
}
```

**Что делает:**
1. Получает компоненты из схемы
2. Для каждой локали создает Tab
3. Для каждого компонента:
   - Клонирует
   - Определяет правильный statePath через `resolveStatePath()`
   - Устанавливает name и statePath
   - Обрабатывает Builder/Repeater если нужно
4. Возвращает массив Tab компонентов

**Результат:**
```php
[
    Tab::make('RU')->key('tab_ru')->schema([
        TextInput::make('title_ru')->statePath('title'),  // HybridDriver: базовая локаль
        Builder::make('blocks_ru')->statePath('data.blocks.ru'),  // JsonDriver
    ]),
    Tab::make('EN')->key('tab_en')->schema([
        TextInput::make('title_en')->statePath('extra.en.title'),  // HybridDriver: перевод
        Builder::make('blocks_en')->statePath('data.blocks.en'),  // JsonDriver
    ]),
]
```

---

### Шаг 3: Определение правильного statePath (resolveStatePath)

**Добавить метод:**

```php
/**
 * Определить правильный statePath для атрибута и локали
 */
protected function resolveStatePath(string $attribute, string $locale): string
{
    // Получаем Livewire компонент и модель
    $livewire = $this->getLivewire();

    // На Create страницах модели нет - используем конфиг
    if (!method_exists($livewire, 'getRecord')) {
        return $this->resolveStatePathFromConfig($attribute, $locale);
    }

    $record = $livewire->getRecord();

    // На Create страницах record = null - используем конфиг
    if (!$record) {
        return $this->resolveStatePathFromConfig($attribute, $locale);
    }

    // Проверяем что модель использует HasTranslations
    if (!method_exists($record, 'driver') || !method_exists($record, 'isTranslatableAttribute')) {
        // Не translatable атрибут - возвращаем как есть
        return $attribute;
    }

    // Проверяем что атрибут translatable
    if (!$record->isTranslatableAttribute($attribute)) {
        return $attribute;
    }

    try {
        // Получаем драйвер для атрибута
        $driver = $record->driver($attribute);
        $baseLocale = $this->resolveBaseLocale($record);

        // Определяем statePath в зависимости от типа драйвера
        if ($driver instanceof \Spatie\Translatable\Drivers\HybridColumnDriver) {
            // HybridColumnDriver: базовая локаль в обычной колонке, переводы в extra
            if ($locale === $baseLocale) {
                return $attribute; // title (обычная колонка)
            } else {
                $storageColumn = $driver->resolveStorageColumn($record);
                return "{$storageColumn}.{$locale}.{$attribute}"; // extra.en.title
            }
        } elseif ($driver instanceof \Spatie\Translatable\Drivers\ExtraOnlyDriver) {
            // ExtraOnlyDriver: все локали в extra
            $storageColumn = $driver->resolveStorageColumn($record);
            return "{$storageColumn}.{$locale}.{$attribute}"; // extra.ru.title
        } else {
            // JsonColumnDriver: стандартный формат
            return "{$attribute}.{$locale}"; // title.en, blocks.ru
        }
    } catch (\Exception $e) {
        // Fallback при любых ошибках
        return "{$attribute}.{$locale}";
    }
}

/**
 * Определить statePath из конфига (для Create страниц)
 */
protected function resolveStatePathFromConfig(string $attribute, string $locale): string
{
    $defaultDriver = config('translatable.default_driver', 'json');
    $baseLocale = config('app.locale', 'ru');

    if ($defaultDriver === 'hybrid') {
        if ($locale === $baseLocale) {
            return $attribute; // title
        } else {
            $storageColumn = config('translatable.storage_column', 'extra');
            return "{$storageColumn}.{$locale}.{$attribute}"; // extra.en.title
        }
    } elseif ($defaultDriver === 'extra_only') {
        $storageColumn = config('translatable.storage_column', 'extra');
        return "{$storageColumn}.{$locale}.{$attribute}"; // extra.ru.title
    }

    // JsonColumnDriver по умолчанию
    return "{$attribute}.{$locale}"; // title.en
}
```

**Что делает:**
1. Проверяет есть ли модель (Edit страница) или нет (Create страница)
2. Если модели нет - использует конфиг (`resolveStatePathFromConfig`)
3. Если модель есть:
   - Получает драйвер для атрибута: `$record->driver($attribute)`
   - Определяет тип драйвера: `instanceof HybridColumnDriver`
   - Возвращает правильный statePath в зависимости от драйвера
4. Fallback на простой формат `{$attribute}.{$locale}` при ошибках

**Примеры результата:**

| Атрибут | Драйвер | Локаль | StatePath |
|---------|---------|--------|-----------|
| title | HybridColumnDriver | ru (базовая) | `title` |
| title | HybridColumnDriver | en | `extra.en.title` |
| description | HybridColumnDriver | en | `extra.en.description` |
| blocks | JsonColumnDriver | ru | `data.blocks.ru` |
| blocks | JsonColumnDriver | en | `data.blocks.en` |
| content | ExtraOnlyDriver | ru | `extra.ru.content` |

---

### Шаг 4: Вспомогательные методы

**Добавить методы:**

```php
/**
 * Получить список локалей
 */
protected function resolveLocales(): array
{
    $configuredLocales = config('filament-language-tabs.default_locales', []);

    if (!empty($configuredLocales)) {
        return array_values(array_unique($configuredLocales));
    }

    return [config('app.locale', 'en')];
}

/**
 * Получить лейбл для локали
 */
protected function resolveLocaleLabel(string $locale): string
{
    $labels = config('filament-language-tabs.locale_labels', []);

    if (isset($labels[$locale])) {
        return $labels[$locale];
    }

    return strtoupper($locale);
}

/**
 * Получить базовую локаль модели
 */
protected function resolveBaseLocale(?Model $record): string
{
    if ($record) {
        if (method_exists($record, 'baseLocale')) {
            return (string)$record->baseLocale();
        }

        if (property_exists($record, 'baseLocale')) {
            return (string)$record->baseLocale;
        }
    }

    $locales = $this->resolveLocales();
    return $locales[0] ?? config('app.locale', 'en');
}
```

**Что делает:**
- `resolveLocales()` - получает список локалей из конфига
- `resolveLocaleLabel()` - получает лейбл для локали (RU, EN, HY...)
- `resolveBaseLocale()` - определяет базовую локаль модели

---

### Шаг 5: Обработка Builder/Repeater (опционально)

Если нужна дополнительная логика для Builder/Repeater (например, установка meta данных):

```php
/**
 * Подготовить Builder для локали
 */
protected function prepareBuilderForLocale(Builder $builder, string $attribute, string $locale): void
{
    // Устанавливаем уникальный key для Builder
    $componentKey = "language_tabs.{$attribute}_{$locale}." . spl_object_id($builder);
    $builder->key($componentKey);

    // Устанавливаем meta данные (для возможного использования в будущем)
    $builder->meta('language_tabs', [
        'attribute' => $attribute,
        'locale' => $locale,
    ]);

    // Можно добавить дополнительные настройки если нужно
    // Например, required/disabled в зависимости от локали
    $requiredLocales = config('filament-language-tabs.required_locales', []);
    if (!in_array($locale, $requiredLocales, true)) {
        $builder->required(false);
    }
}

/**
 * Подготовить Repeater для локали
 */
protected function prepareRepeaterForLocale(Repeater $repeater, string $attribute, string $locale): void
{
    // Аналогично Builder
    $componentKey = "language_tabs.{$attribute}_{$locale}." . spl_object_id($repeater);
    $repeater->key($componentKey);

    $repeater->meta('language_tabs', [
        'attribute' => $attribute,
        'locale' => $locale,
    ]);

    $requiredLocales = config('filament-language-tabs.required_locales', []);
    if (!in_array($locale, $requiredLocales, true)) {
        $repeater->required(false);
    }
}
```

**Что делает:**
- Устанавливает уникальный `key` для Builder/Repeater
- Добавляет meta данные с информацией о локали
- Устанавливает required в зависимости от конфига

**Примечание:** Эти методы ОПЦИОНАЛЬНЫ. StatePath уже правильный, поэтому Builder/Repeater будут работать без дополнительной настройки.

---

### Шаг 6: Backward compatibility (опционально)

Если хочешь сохранить старый API где schema передается в `make()` как массив:

```php
public static function make($schema = null): static
{
    // Если передали schema как единственный аргумент - используем его
    if ($schema !== null && (is_array($schema) || $schema instanceof Closure || $schema instanceof Schema)) {
        return static::makeWithSchema($schema);
    }

    // Иначе - стандартный конструктор от Tabs
    return parent::make();
}

/**
 * Создать LanguageTabs со схемой
 */
public static function makeWithSchema(array | Closure | Schema $schema): static
{
    $static = app(static::class);
    $static->translatableSchema($schema);
    $static->configure();

    return $static;
}
```

**Использование:**
```php
// Старый API (backward compatible)
LanguageTabs::make([
    TextInput::make('title'),
    Builder::make('blocks'),
])

// Новый API (как Tabs)
LanguageTabs::make()
    ->translatableSchema([
        TextInput::make('title'),
        Builder::make('blocks'),
    ])
```

---

## Полный код LanguageTabs

**Файл:** `src/Forms/Components/LanguageTabs.php`

```php
<?php

namespace Pixelpeter\FilamentLanguageTabs\Forms\Components;

use Closure;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class LanguageTabs extends Tabs
{
    use InteractsWithForms;

    protected array | Closure $translatableSchema;

    public static function make($schema = null): static
    {
        if ($schema !== null && (is_array($schema) || $schema instanceof Closure || $schema instanceof Schema)) {
            $static = app(static::class);
            $static->translatableSchema($schema);
            $static->configure();
            return $static;
        }

        return parent::make();
    }

    public function translatableSchema(array | Closure | Schema $schema): static
    {
        $this->translatableSchema = $schema;
        return $this;
    }

    protected function getTranslatableComponents(): array
    {
        $schema = $this->evaluate($this->translatableSchema);

        if ($schema instanceof Schema) {
            return $schema->getComponents();
        }

        return is_array($schema) ? $schema : [];
    }

    public function getDefaultChildComponents(): array
    {
        $components = $this->getTranslatableComponents();
        $locales = $this->resolveLocales();
        $tabs = [];

        foreach ($locales as $locale) {
            $fields = [];

            foreach ($components as $component) {
                $clone = $component->getClone();
                $attribute = $clone->getName();

                $statePath = $this->resolveStatePath($attribute, $locale);

                $clone
                    ->name("{$attribute}_{$locale}")
                    ->statePath($statePath);

                if ($clone instanceof Builder) {
                    $this->prepareBuilderForLocale($clone, $attribute, $locale);
                } elseif ($clone instanceof Repeater) {
                    $this->prepareRepeaterForLocale($clone, $attribute, $locale);
                }

                $fields[] = $clone;
            }

            $tab = Tab::make($this->resolveLocaleLabel($locale))
                ->key("tab_{$locale}")
                ->schema($fields);

            $tabs[] = $tab;
        }

        return $tabs;
    }

    protected function resolveStatePath(string $attribute, string $locale): string
    {
        $livewire = $this->getLivewire();

        if (!method_exists($livewire, 'getRecord')) {
            return $this->resolveStatePathFromConfig($attribute, $locale);
        }

        $record = $livewire->getRecord();

        if (!$record) {
            return $this->resolveStatePathFromConfig($attribute, $locale);
        }

        if (!method_exists($record, 'driver') || !method_exists($record, 'isTranslatableAttribute')) {
            return $attribute;
        }

        if (!$record->isTranslatableAttribute($attribute)) {
            return $attribute;
        }

        try {
            $driver = $record->driver($attribute);
            $baseLocale = $this->resolveBaseLocale($record);

            if ($driver instanceof \Spatie\Translatable\Drivers\HybridColumnDriver) {
                if ($locale === $baseLocale) {
                    return $attribute;
                } else {
                    $storageColumn = $driver->resolveStorageColumn($record);
                    return "{$storageColumn}.{$locale}.{$attribute}";
                }
            } elseif ($driver instanceof \Spatie\Translatable\Drivers\ExtraOnlyDriver) {
                $storageColumn = $driver->resolveStorageColumn($record);
                return "{$storageColumn}.{$locale}.{$attribute}";
            } else {
                return "{$attribute}.{$locale}";
            }
        } catch (\Exception $e) {
            return "{$attribute}.{$locale}";
        }
    }

    protected function resolveStatePathFromConfig(string $attribute, string $locale): string
    {
        $defaultDriver = config('translatable.default_driver', 'json');
        $baseLocale = config('app.locale', 'ru');

        if ($defaultDriver === 'hybrid') {
            if ($locale === $baseLocale) {
                return $attribute;
            } else {
                $storageColumn = config('translatable.storage_column', 'extra');
                return "{$storageColumn}.{$locale}.{$attribute}";
            }
        } elseif ($defaultDriver === 'extra_only') {
            $storageColumn = config('translatable.storage_column', 'extra');
            return "{$storageColumn}.{$locale}.{$attribute}";
        }

        return "{$attribute}.{$locale}";
    }

    protected function resolveLocales(): array
    {
        $configuredLocales = config('filament-language-tabs.default_locales', []);

        if (!empty($configuredLocales)) {
            return array_values(array_unique($configuredLocales));
        }

        return [config('app.locale', 'en')];
    }

    protected function resolveLocaleLabel(string $locale): string
    {
        $labels = config('filament-language-tabs.locale_labels', []);

        if (isset($labels[$locale])) {
            return $labels[$locale];
        }

        return strtoupper($locale);
    }

    protected function resolveBaseLocale(?Model $record): string
    {
        if ($record) {
            if (method_exists($record, 'baseLocale')) {
                return (string)$record->baseLocale();
            }

            if (property_exists($record, 'baseLocale')) {
                return (string)$record->baseLocale;
            }
        }

        $locales = $this->resolveLocales();
        return $locales[0] ?? config('app.locale', 'en');
    }

    protected function prepareBuilderForLocale(Builder $builder, string $attribute, string $locale): void
    {
        $componentKey = "language_tabs.{$attribute}_{$locale}." . spl_object_id($builder);
        $builder->key($componentKey);

        $builder->meta('language_tabs', [
            'attribute' => $attribute,
            'locale' => $locale,
        ]);

        $requiredLocales = config('filament-language-tabs.required_locales', []);
        if (!in_array($locale, $requiredLocales, true)) {
            $builder->required(false);
        }
    }

    protected function prepareRepeaterForLocale(Repeater $repeater, string $attribute, string $locale): void
    {
        $componentKey = "language_tabs.{$attribute}_{$locale}." . spl_object_id($repeater);
        $repeater->key($componentKey);

        $repeater->meta('language_tabs', [
            'attribute' => $attribute,
            'locale' => $locale,
        ]);

        $requiredLocales = config('filament-language-tabs.required_locales', []);
        if (!in_array($locale, $requiredLocales, true)) {
            $repeater->required(false);
        }
    }
}
```

---

## Конфигурация

Нужно добавить в конфиг `translatable.php`:

```php
return [
    // Драйвер по умолчанию (для Create страниц)
    'default_driver' => env('TRANSLATABLE_DRIVER', 'json'), // json|hybrid|extra_only

    // Колонка для хранения переводов (для HybridColumnDriver и ExtraOnlyDriver)
    'storage_column' => 'extra',
];
```

---

## Тестирование

### Тест 1: Edit страница с HybridColumnDriver (title)

```php
// Model
class Post extends Model
{
    use HasTranslations;

    public $translatable = [
        'title' => ['driver' => 'hybrid', 'storage' => 'extra'],
        'description' => ['driver' => 'hybrid', 'storage' => 'extra'],
    ];
}

// Form
LanguageTabs::make([
    TextInput::make('title'),
    Textarea::make('description'),
])
```

**Ожидаемый результат:**
- Tab RU: `title` → читает из колонки `title`, `description` → читает из `description`
- Tab EN: `title` → читает из `extra->en->title`, `description` → читает из `extra->en->description`

### Тест 2: Edit страница с JsonColumnDriver (blocks)

```php
// Model
class Post extends Model
{
    use HasTranslations;

    public $translatable = ['blocks'];

    protected $casts = [
        'blocks' => 'array',
    ];
}

// Form
LanguageTabs::make([
    Builder::make('blocks'),
])
```

**Ожидаемый результат:**
- Tab RU: `blocks` → читает из `data.blocks.ru`
- Tab EN: `blocks` → читает из `data.blocks.en`
- При переключении таба → Builder мгновенно обновляется

### Тест 3: Create страница

```php
// Form
LanguageTabs::make([
    TextInput::make('title'),
    Builder::make('blocks'),
])
```

**Ожидаемый результат:**
- Использует конфиг `translatable.default_driver`
- Если `hybrid` → Tab RU: `title`, Tab EN: `extra.en.title`
- Если `json` → Tab RU: `title.ru`, Tab EN: `title.en`

### Тест 4: Переключение табов

1. Открыть Edit страницу с данными в RU и EN
2. Переключить RU → EN
   - ✅ Все поля должны показать данные EN
   - ✅ Builder/EditorJS должен показать контент EN
   - ✅ Не должно быть задержек
3. Переключить EN → RU
   - ✅ Все поля должны вернуться к RU
   - ✅ Builder/EditorJS должен показать контент RU

---

## Преимущества нового подхода

1. ✅ **Мгновенное переключение** - Filament сам гидрирует компоненты
2. ✅ **Поддержка всех драйверов** - JsonColumnDriver, HybridColumnDriver, ExtraOnlyDriver
3. ✅ **Чистый код** - нет JS событий, нет DOM поиска, нет костылей
4. ✅ **Работает с Create и Edit** - использует конфиг на Create страницах
5. ✅ **Builder/EditorJS работает** - правильный statePath с самого начала
6. ✅ **Backward compatible** - старый API работает

---

## Что удалить из старого кода

Больше НЕ нужны:

1. ❌ `resources/views/forms/components/language-tabs.blade.php` - используется нативный tabs.blade.php от Filament
2. ❌ JS события `language-tab-changed` - Filament сам обновляет
3. ❌ Кнопка "Обновить блоки" - не нужна
4. ❌ Хуки `afterStateHydrated`/`afterStateUpdated` - не нужны
5. ❌ Методы `normaliseAttributeState`, `refreshBuilderComponents` - не нужны
6. ❌ `$currentLocale` property - не нужна
7. ❌ `changeLocale()` метод - не нужен

---

## Возможные проблемы и решения

### Проблема 1: Нет доступа к $record в getDefaultChildComponents

**Решение:** Используем конфиг через `resolveStatePathFromConfig()`

### Проблема 2: Конфиг `translatable.default_driver` не существует

**Решение:** Добавить в `config/translatable.php` или использовать fallback на 'json'

### Проблема 3: Builder UUID разные для разных локалей

**Решение:** Не проблема! StatePath правильный (`data.blocks.ru`), Filament читает правильный массив items с правильными UUID.

### Проблема 4: Модель не использует HasTranslations

**Решение:** В `resolveStatePath()` проверяем `method_exists($record, 'driver')` и fallback на простой формат

---

## Дальнейшие улучшения (опционально)

### 1. Кэширование драйверов

```php
protected array $driverCache = [];

protected function resolveStatePath(string $attribute, string $locale): string
{
    $cacheKey = "{$attribute}_{$locale}";

    if (isset($this->driverCache[$cacheKey])) {
        return $this->driverCache[$cacheKey];
    }

    $statePath = $this->calculateStatePath($attribute, $locale);
    $this->driverCache[$cacheKey] = $statePath;

    return $statePath;
}
```

### 2. Поддержка custom драйверов

```php
protected function resolveStatePath(string $attribute, string $locale): string
{
    // ...

    // Custom драйвер
    if (method_exists($driver, 'resolveFilamentStatePath')) {
        return $driver->resolveFilamentStatePath($attribute, $locale, $record);
    }

    // ...
}
```

### 3. События при переключении табов (если нужно)

Если нужно выполнить какой-то код при переключении табов:

```php
protected function setUp(): void
{
    parent::setUp();

    $this->extraAlpineAttributes([
        'x-on:tab-changed' => '$dispatch("language-tab-switched", { locale: tab.replace("tab_", "") })',
    ]);
}
```

---

## Итоговая структура файлов

```
src/Forms/Components/
├── LanguageTabs.php          ← Новый класс
└── LanguageTabsOld.php        ← Старый класс (для сравнения)

resources/views/forms/components/
└── language-tabs.blade.php    ← УДАЛИТЬ (не нужен)

config/
└── filament-language-tabs.php ← Оставить как есть

config/ (laravel-translatable)
└── translatable.php           ← Добавить default_driver и storage_column
```

---

## Checklist реализации

- [ ] Шаг 1: Базовая структура класса
- [ ] Шаг 2: Метод getDefaultChildComponents()
- [ ] Шаг 3: Метод resolveStatePath()
- [ ] Шаг 4: Вспомогательные методы (resolveLocales, resolveLocaleLabel, resolveBaseLocale)
- [ ] Шаг 5: Методы prepareBuilderForLocale и prepareRepeaterForLocale
- [ ] Шаг 6: Backward compatibility (опционально)
- [ ] Добавить конфиг default_driver в translatable.php
- [ ] Удалить старый blade файл language-tabs.blade.php
- [ ] Тест 1: Edit с HybridColumnDriver (title)
- [ ] Тест 2: Edit с JsonColumnDriver (blocks)
- [ ] Тест 3: Create страница
- [ ] Тест 4: Переключение табов
- [ ] Проверить EditorJS обновляется мгновенно
- [ ] Проверить title/description работают
- [ ] Коммит и deploy
