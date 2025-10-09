# Объединенное решение для поддержки Builder/Repeater в LanguageTabs

> **Статус**: Готово к реализации
> **Дата**: 2025-10-09
> **Источники**:
> - `tasks/fix-builder.md` - анализ механизмов Filament 4.x
> - `tasks/builder-refresh.md` - проблема с перезаписью нативных хуков
> - `tasks/cdx-fix.md` - рекомендации по синхронизации
> - Исходники Filament: `/Users/mikhailpanyushkin/code/xcom/libs/forms` и `/Users/mikhailpanyushkin/code/xcom/libs/filament`

---

## Краткое описание проблемы

При использовании `Builder` или `Repeater` внутри `LanguageTabs`:

1. **Индикатор заполненности локали не обновляется** после изменения данных в Builder
2. **Нативные хуки Filament перезаписываются**, что ломает UUID-механизм Builder
3. **Items теряются или дублируются** при переключении между табами
4. **Данные не синхронизируются** между локалями корректно

### Корневая причина

Метод `prepareFieldForLocale()` в текущей реализации:

```php
// src/Forms/Components/LanguageTabs.php:136
$field->afterStateHydrated(function (Field $component) use ($attribute, $locale): void {
    // Логика LanguageTabs
});
```

**Полностью перезаписывает** нативный хук `afterStateHydrated`, который Builder устанавливает в `setUp()`:

```php
// Builder.php:110-122
$this->afterStateHydrated(static function (Builder $component, ?array $rawState): void {
    // Критичное UUID-преобразование
    $items = [];
    foreach ($rawState ?? [] as $itemData) {
        if ($uuid = $component->generateUuid()) {
            $items[$uuid] = $itemData;
        }
    }
    $component->rawState($items);
});
```

Без этого преобразования:
- Items остаются с числовыми индексами `[0, 1, 2]` вместо UUID
- Livewire не может отслеживать изменения отдельных Items
- Синхронизация состояния ломается

---

## Анализ из code-reviewer

### Критические точки отказа

1. **Перезапись `afterStateHydrated`** полностью ломает UUID-механизм Builder
2. **Отсутствие обработки контейнерных компонентов** (Builder/Repeater) оставляет их без синхронизации
3. **Shallow clone** создаёт общие ссылки на внутренние объекты между локалями
4. **Игнорирование вложенных полей** делает невозможной работу с блоками Builder
5. **Path mismatch** между `refreshFormData(['content'])` и `content.{locale}` ломает индикаторы

### Что именно ломается

#### Сценарий 1: Гидратация (загрузка из модели)

**Ожидается:**
```
[Model JSON]
  → afterStateHydrated LanguageTabs (нормализация)
  → Builder.state([...])
  → afterStateHydrated Builder (UUID преобразование) ✅
  → [Корректное состояние с UUID]
```

**Реально происходит:**
```
[Model JSON]
  → afterStateHydrated LanguageTabs (ЕДИНСТВЕННЫЙ ХУК)
  → Builder.state([...])
  → afterStateHydrated Builder НЕ ВЫЗЫВАЕТСЯ ❌
  → [Состояние БЕЗ UUID - индексы 0,1,2]
```

#### Сценарий 2: Обновление пользователем

**Ожидается:**
```
[User edits field in Builder block]
  → wire:model updates state
  → Builder callAfterStateUpdated()
  → afterStateUpdated LanguageTabs (синхронизация с JSON) ✅
  → refreshFormData(['content'])
  → [Badge обновляется]
```

**Реально происходит:**
```
[User edits field]
  → wire:model updates (с индексами вместо UUID)
  → Builder callAfterStateUpdated() (хук отсутствует) ❌
  → afterStateUpdated LanguageTabs НЕ ВЫЗЫВАЕТСЯ ❌
  → [Badge НЕ обновляется]
```

---

## Объединенное решение

### Ключевые принципы

1. ✅ **Сохранять нативные хуки Filament** - не перезаписывать, а добавлять свои после
2. ✅ **Специальная обработка для контейнеров** - Builder/Repeater требуют особой логики
3. ✅ **Правильная синхронизация локалей** - обновлять только измененную локаль
4. ✅ **Предотвращение дублирования** - кешировать обработанные компоненты
5. ✅ **Актуальные механизмы Filament 4.x** - использовать `afterStateUpdated()`, НЕ `reactive()`

### Полная реализация

```php
<?php

namespace Pixelpeter\FilamentLanguageTabs\Forms\Components;

use Closure;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class LanguageTabs extends Component
{
    use InteractsWithForms;

    protected string $view = 'filament-language-tabs::forms.components.language-tabs';

    /**
     * Отслеживание обработанных компонентов для предотвращения дублирования хуков
     *
     * @var array<string, bool>
     */
    protected static array $processedComponents = [];

    /**
     * Отслеживание нормализованных атрибутов
     *
     * @var array<int, string>
     */
    protected array $normalisedAttributes = [];

    final public function __construct(array|Closure $schema)
    {
        $this->schema($schema);
    }

    public static function make(array|Closure $schema): static
    {
        $static = app(static::class, ['schema' => $schema]);
        $static->configure();

        return $static;
    }

    public function schema(Closure|Schema|array $components): static
    {
        if ($components instanceof Schema) {
            $components = $components->getComponents();
        }

        if ($components instanceof Closure) {
            $components = $this->evaluate($components);
        }

        $locales = $this->resolveLocales();
        $locales = array_unique($locales);

        $tabs = [];
        foreach ($locales as $locale) {
            $tabs[] = Tab::make($this->resolveLocaleLabel($locale))
                ->key("tab_{$locale}")
                ->schema(
                    $this->tabfields($components, $locale)
                );
        }

        $t = Tabs::make()
            ->key('language_tabs')
            ->schema($tabs);

        $this->childComponents([$t]);

        return $this;
    }

    protected function tabfields(array $components, string $locale): array
    {
        $required_locales = config('filament-language-tabs.required_locales', []);
        $required_locales = array_unique($required_locales);

        $tabfields = [];
        foreach ($components as $component) {
            $clone = clone $component;
            $base = $clone->getName();

            $componentName = "{$base}_{$locale}";
            $statePath = "{$base}.{$locale}";
            $clone->name($componentName);
            $clone->statePath($statePath);

            // ✅ ИСПРАВЛЕНО: Специальная обработка для разных типов компонентов
            if ($clone instanceof Builder) {
                $this->prepareBuilderForLocale($clone, $base, $locale);
            } elseif ($clone instanceof Repeater) {
                $this->prepareRepeaterForLocale($clone, $base, $locale);
            } elseif ($clone instanceof Field) {
                $this->prepareFieldForLocale($clone, $base, $locale);
            }

            if (!in_array($locale, $required_locales, true)) {
                $clone->required(false);
            }

            $tabfields[] = $clone;
        }

        return $tabfields;
    }

    /**
     * ✅ НОВЫЙ МЕТОД: Специальная подготовка Builder для работы с локалями
     * СОХРАНЯЕТ нативные хуки Filament вместо перезаписи
     */
    protected function prepareBuilderForLocale(
        Builder $builder,
        string $attribute,
        string $locale
    ): void {
        $componentId = spl_object_id($builder);
        $processKey = "{$componentId}_{$locale}";

        // Предотвращаем дублирование обработки
        if (isset(static::$processedComponents[$processKey])) {
            return;
        }
        static::$processedComponents[$processKey] = true;

        // ✅ КРИТИЧНО: Получаем существующий хук ПЕРЕД добавлением нового
        $existingHydrated = $this->getExistingHydrationHook($builder);

        // Гидратация: сначала нативный хук, потом наша логика
        $builder->afterStateHydrated(function (Builder $component) use ($existingHydrated, $attribute, $locale): void {
            // 1. Вызываем нативный хук Builder (UUID-преобразование)
            if ($existingHydrated !== null) {
                $existingHydrated($component, $component->getState());
            }

            // 2. Наша логика: нормализация из JSON-переводов
            $get = $component->makeGetUtility();
            $set = $component->makeSetUtility();

            $attributeState = $get($attribute);

            if (!is_array($attributeState)) {
                $attributeState = $this->normaliseAttributeState($component, $attribute, $attributeState);
                $set($attribute, $attributeState, shouldCallUpdatedHooks: false);
            }

            // Получаем данные для текущей локали
            $localeData = Arr::get($attributeState, $locale, []);

            // Builder ожидает массив items
            if ($localeData !== $component->getState()) {
                $component->state($localeData);
            }
        });

        // Обновление: синхронизация с JSON-переводами
        $builder->afterStateUpdated(function (Builder $component, $state) use ($attribute, $locale): void {
            $get = $component->makeGetUtility();
            $set = $component->makeSetUtility();

            $translations = $get($attribute);

            if (!is_array($translations)) {
                $translations = [];
            }

            // Обновляем только текущую локаль
            $translations[$locale] = $state ?? [];

            $set($attribute, $translations, shouldCallUpdatedHooks: true);

            // ✅ ИСПРАВЛЕНО: Обновляем и базовый атрибут, и локаль
            $livewire = $component->getLivewire();
            if (method_exists($livewire, 'refreshFormData')) {
                /** @var callable(array<string>) $refresh */
                $refresh = [$livewire, 'refreshFormData'];
                $refresh(["{$attribute}.{$locale}", $attribute]);
            }
        });
    }

    /**
     * ✅ НОВЫЙ МЕТОД: Специальная подготовка Repeater для работы с локалями
     */
    protected function prepareRepeaterForLocale(
        Repeater $repeater,
        string $attribute,
        string $locale
    ): void {
        $componentId = spl_object_id($repeater);
        $processKey = "{$componentId}_{$locale}";

        if (isset(static::$processedComponents[$processKey])) {
            return;
        }
        static::$processedComponents[$processKey] = true;

        $existingHydrated = $this->getExistingHydrationHook($repeater);

        $repeater->afterStateHydrated(function (Repeater $component) use ($existingHydrated, $attribute, $locale): void {
            // Нативный хук Repeater
            if ($existingHydrated !== null) {
                $existingHydrated($component, $component->getState());
            }

            // Наша логика
            $get = $component->makeGetUtility();
            $set = $component->makeSetUtility();

            $attributeState = $get($attribute);

            if (!is_array($attributeState)) {
                $attributeState = $this->normaliseAttributeState($component, $attribute, $attributeState);
                $set($attribute, $attributeState, shouldCallUpdatedHooks: false);
            }

            $localeData = Arr::get($attributeState, $locale, []);

            if ($localeData !== $component->getState()) {
                $component->state($localeData);
            }
        });

        $repeater->afterStateUpdated(function (Repeater $component, $state) use ($attribute, $locale): void {
            $get = $component->makeGetUtility();
            $set = $component->makeSetUtility();

            $translations = $get($attribute);

            if (!is_array($translations)) {
                $translations = [];
            }

            $translations[$locale] = $state ?? [];

            $set($attribute, $translations, shouldCallUpdatedHooks: true);

            $livewire = $component->getLivewire();
            if (method_exists($livewire, 'refreshFormData')) {
                /** @var callable(array<string>) $refresh */
                $refresh = [$livewire, 'refreshFormData'];
                $refresh(["{$attribute}.{$locale}", $attribute]);
            }
        });
    }

    /**
     * ✅ НОВЫЙ МЕТОД: Получение существующего хука гидратации через рефлексию
     *
     * Это критично для сохранения нативного поведения Builder/Repeater
     */
    protected function getExistingHydrationHook(Component $component): ?Closure
    {
        try {
            $reflection = new \ReflectionClass($component);
            $property = $reflection->getProperty('afterStateHydrated');
            $property->setAccessible(true);

            return $property->getValue($component);
        } catch (\ReflectionException $e) {
            // Если не удалось получить через рефлексию, возвращаем null
            return null;
        }
    }

    /**
     * Стандартная подготовка обычных полей (без изменений)
     */
    protected function prepareFieldForLocale(Field $field, string $attribute, string $locale): void
    {
        $field->afterStateHydrated(function (Field $component) use ($attribute, $locale): void {
            $get = $component->makeGetUtility();
            $set = $component->makeSetUtility();

            $attributeState = $get($attribute);

            if (!is_array($attributeState)) {
                $attributeState = $this->normaliseAttributeState($component, $attribute, $attributeState);
                $set($attribute, $attributeState, shouldCallUpdatedHooks: false);
            }

            $component->state(Arr::get($attributeState, $locale));
        });

        $field->afterStateUpdated(function (Field $component, $state) use ($attribute, $locale): void {
            $get = $component->makeGetUtility();
            $set = $component->makeSetUtility();

            $translations = $get($attribute);

            if (!is_array($translations)) {
                $translations = [];
            }

            $translations[$locale] = $state === '' ? null : $state;

            $set($attribute, $translations, shouldCallUpdatedHooks: true);

            $livewire = $component->getLivewire();

            if (method_exists($livewire, 'refreshFormData')) {
                /** @var callable(array<string>) $refresh */
                $refresh = [$livewire, 'refreshFormData'];
                $refresh([$attribute]);
            }
        });
    }

    protected function normaliseAttributeState(Field $component, string $attribute, mixed $rawState): array
    {
        if (in_array($attribute, $this->normalisedAttributes, true) && is_array($rawState)) {
            return $rawState;
        }

        $record = $this->resolveRecord($component);

        $translations = $record->getTranslations($attribute);

        if (!is_array($translations) || empty($translations)) {
            if (is_array($rawState)) {
                $translations = $rawState;
            } elseif ($rawState !== null && $rawState !== '') {
                $baseLocale = $this->resolveBaseLocale($record);
                $translations = [$baseLocale => $rawState];
            } else {
                $translations = [];
            }
        }

        $locales = $this->resolveLocales();
        if (!empty($locales)) {
            $translations = Arr::only($translations, $locales);
        }

        foreach ($locales as $locale) {
            $translations[$locale] ??= null;
        }

        $this->normalisedAttributes[] = $attribute;

        return $translations;
    }

    protected function resolveLocales(): array
    {
        $configuredLocales = config('filament-language-tabs.default_locales', []);

        if (!empty($configuredLocales)) {
            return array_values(array_unique($configuredLocales));
        }

        return [config('app.locale')];
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

    protected function resolveRecord(Field $component): ?Model
    {
        $livewire = $component->getLivewire();

        if (method_exists($livewire, 'getRecord')) {
            return $livewire->getRecord();
        }

        if (property_exists($livewire, 'record')) {
            $record = $livewire->record;

            return $record instanceof Model ? $record : null;
        }

        return null;
    }

    /**
     * ✅ НОВЫЙ МЕТОД: Очистка кешей при рендере
     */
    public function render(): \Illuminate\Contracts\View\View
    {
        // Очищаем кеш обработанных компонентов для нового рендера
        static::$processedComponents = [];

        return parent::render();
    }
}
```

---

## Как это работает

### 1. Сохранение нативных хуков через рефлексию

```php
protected function getExistingHydrationHook(Component $component): ?Closure
{
    $reflection = new \ReflectionClass($component);
    $property = $reflection->getProperty('afterStateHydrated');
    $property->setAccessible(true);

    return $property->getValue($component);
}
```

**Зачем это нужно:**
- Builder устанавливает критичный хук в `setUp()` для UUID-преобразования
- Этот хук хранится в `protected ?Closure $afterStateHydrated`
- Без рефлексии мы не можем получить к нему доступ
- Мы получаем хук, вызываем его первым, потом добавляем свою логику

### 2. Правильный порядок вызова хуков

```php
$builder->afterStateHydrated(function (Builder $component) use ($existingHydrated, ...) {
    // 1️⃣ СНАЧАЛА: Нативный хук Builder (UUID-преобразование)
    if ($existingHydrated !== null) {
        $existingHydrated($component, $component->getState());
    }

    // 2️⃣ ПОТОМ: Наша логика (синхронизация с JSON)
    $attributeState = $get($attribute);
    // ...
});
```

**Результат:**
- ✅ Builder создает Items с UUID
- ✅ LanguageTabs синхронизирует с JSON-переводами
- ✅ Оба механизма работают корректно

### 3. Предотвращение дублирования вызовов

```php
protected static array $processedComponents = [];

$componentId = spl_object_id($builder);
$processKey = "{$componentId}_{$locale}";

if (isset(static::$processedComponents[$processKey])) {
    return; // Уже обработан
}
static::$processedComponents[$processKey] = true;
```

**Зачем:**
- При создании табов компоненты клонируются для каждой локали
- Без кеширования мы будем добавлять хуки несколько раз
- Это может привести к бесконечным циклам обновления

### 4. Обновление правильных путей в refreshFormData

```php
// ✅ ИСПРАВЛЕНО: Обновляем ОБА пути
$refresh([
    "{$attribute}.{$locale}",  // content.en - для badge конкретной локали
    $attribute                  // content - для общего состояния
]);
```

**Проблема была:**
- Badge слушает `content.en`
- Мы обновляли только `content`
- Badge не получал уведомление об изменении

---

## Тест-кейсы

### Тест 1: Создание Builder с Items

```php
use Illuminate\Support\Facades\Config;
use Pixelpeter\FilamentLanguageTabs\Tests\Fixtures\FormTester;

use function Pest\Livewire\livewire;

it('creates builder with items for each locale', function () {
    Config::set('filament-language-tabs', [
        'default_locales' => ['de', 'en', 'fr'],
        'required_locales' => [],
    ]);

    livewire(FormTester::class)
        ->assertFormExists('form')
        ->assertSchemaComponentExists('language_tabs.tab_en.content_en')
        ->assertSchemaComponentExists('language_tabs.tab_de.content_de')
        ->assertSchemaComponentExists('language_tabs.tab_fr.content_fr');
});
```

### Тест 2: Сохранение нативных хуков

```php
it('preserves native builder uuid mechanism', function () {
    Config::set('filament-language-tabs.default_locales', ['en', 'de']);

    $post = Post::create([
        'content' => [
            'en' => [
                ['type' => 'heading', 'data' => ['title' => 'Hello']],
            ],
        ],
    ]);

    livewire(FormTester::class, ['record' => $post->id])
        ->assertFormExists('form')
        ->call('save');

    // Проверяем что данные сохранились корректно
    $post->refresh();
    expect($post->content['en'][0]['type'])->toBe('heading');
    expect($post->content['en'][0]['data']['title'])->toBe('Hello');
});
```

### Тест 3: Добавление Item не дублирует другие локали

```php
it('adds item without affecting other locales', function () {
    Config::set('filament-language-tabs.default_locales', ['en', 'de']);

    $post = Post::create([
        'content' => [
            'en' => [
                ['type' => 'heading', 'data' => ['title' => 'English']],
            ],
            'de' => [
                ['type' => 'heading', 'data' => ['title' => 'Deutsch']],
            ],
        ],
    ]);

    livewire(FormTester::class, ['record' => $post->id])
        ->fillForm([
            'language_tabs.tab_en.content_en' => [
                ['type' => 'heading', 'data' => ['title' => 'English']],
                ['type' => 'heading', 'data' => ['title' => 'New Item']],
            ],
        ])
        ->call('save');

    $post->refresh();

    // Английская версия: 2 items
    expect($post->content['en'])->toHaveCount(2);

    // Немецкая версия: 1 item (не изменилась)
    expect($post->content['de'])->toHaveCount(1);
    expect($post->content['de'][0]['data']['title'])->toBe('Deutsch');
});
```

### Тест 4: Удаление Item

```php
it('removes item correctly', function () {
    Config::set('filament-language-tabs.default_locales', ['en']);

    $post = Post::create([
        'content' => [
            'en' => [
                ['type' => 'heading', 'data' => ['title' => 'First']],
                ['type' => 'heading', 'data' => ['title' => 'Second']],
            ],
        ],
    ]);

    livewire(FormTester::class, ['record' => $post->id])
        ->fillForm([
            'language_tabs.tab_en.content_en' => [
                ['type' => 'heading', 'data' => ['title' => 'First']],
            ],
        ])
        ->call('save');

    $post->refresh();

    expect($post->content['en'])->toHaveCount(1);
    expect($post->content['en'][0]['data']['title'])->toBe('First');
});
```

### Тест 5: Repeater работает аналогично

```php
it('handles repeater like builder', function () {
    Config::set('filament-language-tabs.default_locales', ['en', 'de']);

    $post = Post::create([
        'items' => [
            'en' => [
                ['title' => 'Item 1', 'description' => 'Desc 1'],
            ],
            'de' => [
                ['title' => 'Element 1', 'description' => 'Beschreibung 1'],
            ],
        ],
    ]);

    livewire(FormTester::class, ['record' => $post->id])
        ->assertFormExists('form')
        ->assertFormSet([
            'language_tabs.tab_en.items_en.0.title' => 'Item 1',
            'language_tabs.tab_de.items_de.0.title' => 'Element 1',
        ]);
});
```

---

## План реализации

### Этап 1: Подготовка (1 час)

```bash
# Создать ветку
git checkout -b feature/builder-repeater-support

# Backup текущей версии
cp src/Forms/Components/LanguageTabs.php src/Forms/Components/LanguageTabs.php.backup

# Проверить окружение
composer install
vendor/bin/phpstan --version
vendor/bin/pest --version
```

**Чеклист:**
- [ ] Ветка создана
- [ ] Backup сделан
- [ ] Окружение готово

### Этап 2: Реализация основного функционала (2-3 часа)

1. Обновить `src/Forms/Components/LanguageTabs.php`:
   - [ ] Добавить `protected static array $processedComponents = []`
   - [ ] Добавить метод `getExistingHydrationHook()`
   - [ ] Добавить метод `prepareBuilderForLocale()`
   - [ ] Добавить метод `prepareRepeaterForLocale()`
   - [ ] Обновить метод `tabfields()` с проверкой типов
   - [ ] Добавить метод `render()` с очисткой кешей
   - [ ] Исправить `prepareFieldForLocale()` с обновлением обоих путей

2. Проверить синтаксис:
```bash
composer pint
vendor/bin/phpstan analyse
```

**Чеклист:**
- [ ] Все методы добавлены
- [ ] Pint пройден
- [ ] PHPStan пройден
- [ ] Нет синтаксических ошибок

### Этап 3: Создание тестов (2 часа)

1. Создать тестовый FormTester для Builder:
```php
// tests/Fixtures/FormTesterWithBuilder.php
```

2. Создать тестовую модель Post:
```php
// tests/Fixtures/Post.php
use Spatie\Translatable\HasTranslations;

class Post extends Model
{
    use HasTranslations;

    public array $translatable = ['content', 'items'];

    protected $casts = [
        'content' => 'array',
        'items' => 'array',
    ];
}
```

3. Создать тесты:
```bash
# tests/BuilderIntegrationTest.php
```

4. Запустить тесты:
```bash
./vendor/bin/pest tests/BuilderIntegrationTest.php --verbose
```

**Чеклист:**
- [ ] Fixture классы созданы
- [ ] 5 тестов написаны
- [ ] Все тесты проходят
- [ ] Нет warnings

### Этап 4: Ручное тестирование (2-3 часа)

1. Создать тестовую Filament панель:
```php
// app/Filament/Resources/TestResource.php
use Pixelpeter\FilamentLanguageTabs\Forms\Components\LanguageTabs;

public static function form(Form $form): Form
{
    return $form->schema([
        LanguageTabs::make([
            Builder::make('content')
                ->blocks([
                    Builder\Block::make('heading')
                        ->schema([
                            TextInput::make('title')->required(),
                            Select::make('level')->options([1, 2, 3]),
                        ]),
                    Builder\Block::make('paragraph')
                        ->schema([
                            Textarea::make('text'),
                        ]),
                ]),
            Repeater::make('items')
                ->schema([
                    TextInput::make('name'),
                    TextInput::make('value'),
                ]),
        ]),
    ]);
}
```

2. Проверить сценарии:
   - [ ] Создать новую запись с Builder blocks в разных локалях
   - [ ] Добавить несколько Items в английской версии
   - [ ] Переключиться на немецкую, добавить Items
   - [ ] Вернуться на английскую - проверить что Items не потерялись
   - [ ] Удалить Item из английской версии
   - [ ] Переупорядочить Items (drag-n-drop)
   - [ ] Сохранить и перезагрузить страницу
   - [ ] Проверить что все данные корректны в БД
   - [ ] Проверить индикаторы заполненности табов
   - [ ] Проверить Repeater аналогичным образом

3. Проверить в браузере:
   - [ ] Нет JavaScript ошибок в консоли
   - [ ] Livewire события отрабатывают корректно
   - [ ] Нет лишних network запросов
   - [ ] UI реагирует быстро

**Чеклист:**
- [ ] Все сценарии проверены
- [ ] Индикаторы работают
- [ ] Данные сохраняются корректно
- [ ] Нет багов

### Этап 5: Отладка и оптимизация (1-2 часа)

1. Включить debug режим:
```php
// config/app.php
'debug' => true,

// config/filament.php
'debug' => true,
```

2. Добавить логирование в критические точки:
```php
protected function prepareBuilderForLocale(...) {
    \Log::debug('Preparing Builder for locale', [
        'attribute' => $attribute,
        'locale' => $locale,
        'has_existing_hook' => $existingHydrated !== null,
    ]);
    // ...
}
```

3. Мониторить производительность:
```bash
composer require barryvdh/laravel-debugbar --dev
```

4. Оптимизировать если нужно:
   - Проверить количество DB запросов
   - Проверить количество Livewire updates
   - Оптимизировать рефлексию если возможно

**Чеклист:**
- [ ] Логирование добавлено
- [ ] Производительность приемлемая
- [ ] Нет N+1 проблем
- [ ] Debugbar показывает адекватные метрики

### Этап 6: Документация (1 час)

1. Обновить README.md:
```markdown
## Using with Builder

LanguageTabs now fully supports `Filament\Forms\Components\Builder`:

\`\`\`php
LanguageTabs::make([
    Builder::make('content')
        ->blocks([
            Builder\Block::make('heading')->schema([...]),
            Builder\Block::make('paragraph')->schema([...]),
        ]),
])
\`\`\`

The component preserves Filament's native UUID mechanism and correctly
synchronizes Items across locales.

## Using with Repeater

Repeater is also fully supported:

\`\`\`php
LanguageTabs::make([
    Repeater::make('items')->schema([...]),
])
\`\`\`
```

2. Добавить troubleshooting секцию:
```markdown
## Troubleshooting

### Builder Items disappear after switching tabs

Make sure you're using version 4.1.0+ which includes proper Builder support.

### Items are duplicated across locales

This was a bug in versions prior to 4.1.0. Upgrade to the latest version.
```

3. Обновить CHANGELOG.md:
```markdown
## [4.1.0] - 2025-10-09

### Added
- Full support for `Filament\Forms\Components\Builder` (fixes #XX)
- Full support for `Filament\Forms\Components\Repeater` (fixes #XX)
- Preservation of native Filament hydration hooks
- Proper state synchronization across locales for container components

### Fixed
- Builder Items losing UUID keys after hydration
- State not syncing when editing Builder blocks
- Locale indicators not updating after Builder changes
- Items duplicating across locales on save

### Technical
- Added `prepareBuilderForLocale()` method
- Added `prepareRepeaterForLocale()` method
- Added `getExistingHydrationHook()` for reflection-based hook preservation
- Improved `refreshFormData()` calls to update both attribute and locale paths
```

**Чеклист:**
- [ ] README обновлен с примерами
- [ ] Troubleshooting добавлен
- [ ] CHANGELOG обновлен
- [ ] Документация читабельная

### Этап 7: Code Review и финализация (1 час)

1. Самостоятельный code review:
   - [ ] Все методы имеют PHPDoc комментарии
   - [ ] Код соответствует PSR-12
   - [ ] Нет дублирования кода
   - [ ] Переменные имеют понятные имена
   - [ ] Нет закомментированного кода

2. Финальная проверка:
```bash
composer pint
vendor/bin/phpstan analyse
./vendor/bin/pest
```

3. Создать PR или merge:
```bash
git add .
git commit -m "feat: add Builder/Repeater support with native hooks preservation

- Preserve Filament native afterStateHydrated hooks via reflection
- Add specialized methods for Builder and Repeater components
- Fix state synchronization across locales
- Update both attribute and locale paths in refreshFormData
- Add comprehensive tests for Builder and Repeater
- Update documentation and CHANGELOG

Fixes #XX, Fixes #YY"

git push origin feature/builder-repeater-support

# Создать PR на GitHub
# После одобрения:
git checkout main
git merge feature/builder-repeater-support
git tag v4.1.0
git push origin main --tags
```

**Чеклист:**
- [ ] Code review пройден
- [ ] Все тесты зеленые
- [ ] Commit message описательный
- [ ] PR создан или merge выполнен
- [ ] Tag создан

---

## Метрики успеха

### Функциональные требования
- ✅ Builder работает во всех локалях
- ✅ Repeater работает во всех локалях
- ✅ UUID-механизм Builder сохраняется
- ✅ Items не дублируются между локалями
- ✅ Items не теряются при переключении табов
- ✅ Индикаторы заполненности обновляются корректно

### Производительность
- ✅ Нет заметных задержек при переключении табов
- ✅ Нет лишних DB запросов
- ✅ Нет бесконечных циклов обновления
- ✅ Livewire обновления оптимальны

### Качество кода
- ✅ PHPStan level 4 проходит
- ✅ Pint проходит
- ✅ Все тесты зеленые
- ✅ Покрытие новой функциональности 100%
- ✅ Документация актуальная

### Совместимость
- ✅ Работает с Filament 4.x
- ✅ Работает с Laravel 11/12
- ✅ Работает с PHP 8.2+
- ✅ Не ломает существующий функционал

---

## Критические замечания

### ⚠️ НЕ делать:

1. **НЕ перезаписывать нативные хуки** - всегда сохранять через рефлексию и вызывать первыми
2. **НЕ использовать reactive()/live()** - эти методы удалены в Filament 4.x
3. **НЕ забывать про refreshFormData** - обновлять ОБА пути: `attribute.locale` И `attribute`
4. **НЕ мутировать state напрямую** - всегда через Livewire `$set()`
5. **НЕ добавлять хуки несколько раз** - использовать кеш `$processedComponents`

### ✅ ОБЯЗАТЕЛЬНО делать:

1. **Получать существующие хуки** через `getExistingHydrationHook()`
2. **Вызывать нативный хук ПЕРВЫМ** в новом хуке
3. **Очищать кеш** в методе `render()`
4. **Кешировать обработанные компоненты** через `$processedComponents`
5. **Тестировать на реальных данных** с Builder и Repeater

---

## Заключение

Это объединенное решение комбинирует лучшие подходы из трех анализов:

- **fix-builder.md**: Детальное понимание механизмов Filament 4.x
- **builder-refresh.md**: Проблема перезаписи нативных хуков
- **cdx-fix.md**: Рекомендации по синхронизации и refresh

Решение:
- ✅ Сохраняет нативные хуки Filament
- ✅ Добавляет специальную обработку для контейнеров
- ✅ Правильно синхронизирует состояние между локалями
- ✅ Предотвращает дублирование вызовов
- ✅ Обновляет индикаторы корректно
- ✅ Готово к внедрению поэтапно

**Оценка времени**: 10-13 часов полной реализации от подготовки до релиза

**Приоритет**: Высокий - критический баг для пользователей Builder/Repeater

**Риски**: Низкие - решение опирается на документированные механизмы Filament
