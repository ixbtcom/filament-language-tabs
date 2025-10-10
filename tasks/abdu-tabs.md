# Анализ filament-translatable-tabs (Abdulmajeed Jamaan)

> **Дата**: 2025-10-10
> **Источник**: `/Users/mikhailpanyushkin/code/xcom/libs/filament-translatable-tabs`
> **Цель**: Понять почему там Builder мгновенно переключается без JS событий

---

## Ключевое отличие от нашего пакета

### TranslatableTabs (Abdulmajeed)

```php
class TranslatableTabs extends Tabs  // ← Наследуется от Tabs!
{
    public function getDefaultChildComponents(): array
    {
        $components = parent::getDefaultChildComponents();

        $tabs = [];
        foreach ($this->getLocales() as $locale => $label) {
            $fields = [];

            foreach ($components as $component) {
                // Клонируем и СРАЗУ устанавливаем правильный statePath
                $field = $component
                    ->getClone()
                    ->name("{$component->getName()}.$locale")
                    ->statePath("{$component->getStatePath(false)}.$locale");

                $fields[] = $field;
            }

            $tab = TranslatableTab::make($label)
                ->locale($locale)
                ->schema($fields);

            $tabs[] = $tab;
        }

        return $tabs;
    }
}
```

### LanguageTabs (наш пакет)

```php
class LanguageTabs extends Component  // ← Наследуется от Component!
{
    public function schema(Closure|Schema|array $components): static
    {
        // Создаем Tabs как CHILD component
        $tabsComponent = Tabs::make()
            ->schema($tabs);

        $this->childComponents([
            $tabsComponent,
            $actionsComponent,
        ]);
    }
}
```

---

## Почему TranslatableTabs работает без JS

### 1. Правильная иерархия наследования

**TranslatableTabs наследуется от Tabs**, поэтому:
- Сам ЯВЛЯЕТСЯ табами
- Рендерится как нативный Filament Tabs
- Использует все встроенные механизмы Tabs

**LanguageTabs наследуется от Component**, поэтому:
- Это обертка вокруг Tabs
- Tabs создается как child component
- Теряется прямой контроль над табами

### 2. StatePath устанавливается СРАЗУ правильно

В `getDefaultChildComponents()` (вызывается при создании схемы):

```php
$field = $component
    ->getClone()
    ->statePath("{$component->getStatePath(false)}.$locale");  // ← СРАЗУ правильный path!
```

**Результат:**
- Builder для EN: `statePath = "data.blocks.en"`
- Builder для RU: `statePath = "data.blocks.ru"`
- Каждый Builder ЗНАЕТ свою локаль через statePath

**Когда пользователь переключает таб:**
1. Alpine показывает/скрывает tabpanel
2. Filament видит что табpanel стал видимым
3. Filament гидрирует компоненты внутри с их statePath
4. Builder с `statePath = "data.blocks.ru"` читает `$wire.get('data.blocks.ru')`
5. Получает правильные данные для RU
6. EditorJS автоматически обновляется через Alpine $entangle

**Никакого JS не нужно!** Всё работает через нативные механизмы Filament.

### 3. Нет попыток переопределить state через хуки

TranslatableTabs НЕ использует:
- `afterStateHydrated()` - не нужен
- `afterStateUpdated()` - не нужен
- JavaScript события - не нужны
- Кнопка "Обновить блоки" - не нужна

Всё работает автоматически, потому что statePath правильный с самого начала.

---

## Почему LanguageTabs НЕ работает

### 1. Tabs создается как child component

```php
$tabsComponent = Tabs::make()
    ->key('language_tabs')
    ->schema($tabs);

$this->childComponents([
    $tabsComponent,
    $actionsComponent,
]);
```

**Проблема:** LanguageTabs - это Component, а не Tabs. Это обертка.

### 2. StatePath устанавливается через хуки

В `prepareBuilderForLocale()`:

```php
$builder->afterStateHydrated(function (Builder $component) use ($attribute, $locale): void {
    // Пытаемся установить state через хук
    $localeData = Arr::get($attributeState, $locale);
    $component->state(is_array($localeData) ? $localeData : []);
});
```

**Проблема:**
- Хук срабатывает только ОДИН РАЗ при первой гидратации
- При переключении таба хук НЕ вызывается снова
- Builder продолжает показывать старые данные

### 3. Попытки использовать afterStateHydrated на Tabs

```php
$tabsComponent = Tabs::make()
    ->afterStateHydrated(function (Tabs $component, $state) use ($locales): void {
        // ❌ НИКОГДА НЕ ВЫЗЫВАЕТСЯ!
        $this->currentLocale = $locales[$index - 1] ?? null;
    })
```

**Проблема:** Tabs НЕ имеет `HasState` trait, эти методы не работают.

---

## Решение для LanguageTabs

### Вариант 1: Переделать LanguageTabs чтобы наследовался от Tabs (РЕКОМЕНДУЕТСЯ)

**Изменения:**

```php
// Было
class LanguageTabs extends Component
{
    protected string $view = 'filament-language-tabs::forms.components.language-tabs';

    public function schema(Closure|Schema|array $components): static
    {
        // Создавали Tabs как child
        $tabsComponent = Tabs::make()->schema($tabs);
        $this->childComponents([$tabsComponent]);
    }
}
```

**Стало:**

```php
// Как у Abdulmajeed
class LanguageTabs extends Tabs
{
    // Убираем $view - используется view от Tabs

    public function getDefaultChildComponents(): array
    {
        $components = $this->evaluate($this->schema);  // Получаем исходные компоненты

        $locales = $this->resolveLocales();
        $tabs = [];

        foreach ($locales as $locale) {
            $fields = [];

            foreach ($components as $component) {
                $clone = $component->getClone();
                $base = $clone->getName();

                // СРАЗУ правильный statePath
                $clone
                    ->name("{$base}_{$locale}")
                    ->statePath("{$base}.{$locale}");

                if ($clone instanceof Builder) {
                    $this->prepareBuilderForLocale($clone, $base, $locale);
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
}
```

**Преимущества:**
- ✅ Работает как нативный Tabs
- ✅ StatePath правильный сразу
- ✅ Не нужны JS события
- ✅ Не нужна кнопка "Обновить блоки"
- ✅ Builder переключается мгновенно

**Недостатки:**
- ⚠️ Breaking change - меняется API
- ⚠️ Нужно переписать prepareBuilderForLocale - убрать хуки
- ⚠️ Нужно убрать view (используется нативный tabs.blade.php)

---

### Вариант 2: Использовать livewireProperty для Tabs (СЛОЖНО)

Как в tabs.blade.php (строки 127-194) - использовать Livewire property для активного таба:

```php
$tabsComponent = Tabs::make()
    ->livewireProperty('activeLanguageTab')
    ->schema($tabs);
```

**Проблема:**
- Нужно добавлять property в КАЖДЫЙ Livewire компонент где используется LanguageTabs
- Не работает "из коробки"
- Усложняет использование

---

### Вариант 3: Продолжать использовать JS события (ТЕКУЩИЙ)

Текущий подход с `language-tab-changed` событием и обновлением EditorJS через JavaScript.

**Проблемы:**
- ❌ Сложный код с событиями
- ❌ Зависит от DOM структуры
- ❌ Ищет EditorJS по селекторам
- ❌ Может сломаться при изменении Filament
- ❌ Не находит Builder правильно

---

## Рекомендация

**Переделать LanguageTabs по образцу TranslatableTabs:**

1. Наследоваться от Tabs
2. Переопределить `getDefaultChildComponents()`
3. Устанавливать statePath СРАЗУ правильно
4. Убрать все JS события
5. Убрать кнопку "Обновить блоки"

**Время реализации:** ~2-3 часа

**Риск:** Средний - breaking change API, но работает гарантированно

**Backward compatibility:** Можно сохранить, если добавить алиас метод `make()` который принимает schema как аргумент (как сейчас).

---

## Детальное сравнение

### Создание компонента

**TranslatableTabs:**
```php
TranslatableTabs::make()
    ->locales(['en', 'ru'])
    ->schema([
        TextInput::make('title'),
        Builder::make('blocks'),
    ])
```

**LanguageTabs (текущий):**
```php
LanguageTabs::make([
    TextInput::make('title'),
    Builder::make('blocks'),
])
```

**LanguageTabs (после переделки):**
```php
LanguageTabs::make()
    ->schema([
        TextInput::make('title'),
        Builder::make('blocks'),
    ])
```

### Структура DOM

**TranslatableTabs:**
```html
<div x-data="tabsSchemaComponent(...)">  <!-- Сам является Tabs -->
    <div role="tabpanel" id="tab_en">
        <input name="title.en" wire:model="data.title.en">
        <div wire:model="data.blocks.en">...</div>
    </div>
    <div role="tabpanel" id="tab_ru">
        <input name="title.ru" wire:model="data.title.ru">
        <div wire:model="data.blocks.ru">...</div>
    </div>
</div>
```

**LanguageTabs (текущий):**
```html
<div>  <!-- Обертка LanguageTabs -->
    <div x-data="tabsSchemaComponent(...)">  <!-- Child Tabs -->
        <div role="tabpanel" id="tab_en">...</div>
        <div role="tabpanel" id="tab_ru">...</div>
    </div>
    <div>  <!-- Actions кнопка -->
        <button wire:click="refreshBuilderComponents">Обновить блоки</button>
    </div>
</div>
<script>
    // Куча JS для обновления EditorJS...
</script>
```

---

## ВАЖНО: Почему TranslatableTabs НЕ РАБОТАЕТ с title/description

### Модифицированный laravel-translatable использует систему драйверов

В `/Users/mikhailpanyushkin/code/xcom/laravel-translatable` есть 3 драйвера:

#### 1. JsonColumnDriver (стандартный Spatie)
**Структура данных:**
```json
{
  "title": { "ru": "Russian", "en": "English" },
  "blocks": { "ru": [...], "en": [...] }
}
```
**StatePath в Filament:** `title.en`, `blocks.ru`

#### 2. HybridColumnDriver (гибрид)
**Структура данных:**
- Базовая локаль в обычной колонке: `title = "Russian title"`
- Переводы в JSON колонке `extra`:
```json
{
  "extra": {
    "en": { "title": "English title", "description": "..." },
    "hy": { "title": "Armenian title", "description": "..." }
  }
}
```
**StatePath в Filament:**
- Базовая локаль: `title` (обычная колонка)
- Переводы: `extra.en.title`, `extra.hy.title`

#### 3. ExtraOnlyDriver (все в extra)
**Структура данных:**
```json
{
  "extra": {
    "ru": { "title": "Russian", "description": "...", "blocks": [...] },
    "en": { "title": "English", "description": "...", "blocks": [...] }
  }
}
```
**StatePath в Filament:** `extra.ru.title`, `extra.en.blocks`

### Почему TranslatableTabs НЕ работает

TranslatableTabs делает так:

```php
$field = $component
    ->getClone()
    ->statePath("{$component->getStatePath(false)}.$locale");
```

**Для поля `title` и локали `en` получается:**
- ✅ JsonColumnDriver: `statePath = "title.en"` - РАБОТАЕТ
- ❌ HybridColumnDriver: `statePath = "title.en"` - НЕ РАБОТАЕТ (нужен `extra.en.title`)
- ❌ ExtraOnlyDriver: `statePath = "title.en"` - НЕ РАБОТАЕТ (нужен `extra.en.title`)

**Для поля `blocks` (Builder) и локали `en`:**
- ✅ JsonColumnDriver: `statePath = "blocks.en"` - РАБОТАЕТ
- ❌ HybridColumnDriver: `statePath = "blocks.en"` - НЕ РАБОТАЕТ (нужен `extra.en.blocks`)
- ❌ ExtraOnlyDriver: `statePath = "blocks.en"` - РАБОТАЕТ (если blocks в extra)

### Почему LanguageTabs работает

LanguageTabs использует метод `getTranslations()`:

```php
$attributeState = $get($attribute);  // "title"

if (! is_array($attributeState)) {
    $attributeState = $this->normaliseAttributeState($component, $attribute, $attributeState);
}

// normaliseAttributeState вызывает:
$translations = $record?->getTranslations($attribute);
// Возвращает: ['ru' => 'Russian', 'en' => 'English']
```

**`getTranslations()` работает со ВСЕМИ драйверами:**
- JsonColumnDriver: читает из `title` JSON колонки
- HybridColumnDriver: собирает из `title` (базовая) + `extra->en->title` (переводы)
- ExtraOnlyDriver: читает из `extra->ru->title`, `extra->en->title`

**Поэтому LanguageTabs универсальный!**

---

## Выводы

**TranslatableTabs работает быстро но только с JsonColumnDriver:**

1. ✅ Наследуется от Tabs - использует нативные механизмы
2. ✅ StatePath правильный с самого начала
3. ✅ Filament сам гидрирует компоненты при переключении
4. ❌ НЕ работает с HybridColumnDriver и ExtraOnlyDriver (нужен другой statePath)

**LanguageTabs работает медленно но универсально:**

1. ✅ Работает со ВСЕМИ драйверами (через `getTranslations()`)
2. ✅ Поддерживает HybridColumnDriver (title в обычной колонке + extra)
3. ✅ Поддерживает ExtraOnlyDriver (все в extra)
4. ❌ Наследуется от Component - обертка вокруг Tabs
5. ❌ StatePath устанавливается через хуки (вызываются 1 раз)
6. ❌ Нужны костыли с JS событиями для обновления

**Решение 1: Доработать TranslatableTabs для поддержки драйверов**

Можно добавить в TranslatableTabs определение драйвера и построение правильного statePath:

```php
class TranslatableTabs extends Tabs
{
    public function getDefaultChildComponents(): array
    {
        $components = parent::getDefaultChildComponents();
        $tabs = [];

        foreach ($this->getLocales() as $locale => $label) {
            $fields = [];

            foreach ($components as $component) {
                $clone = $component->getClone();
                $attribute = $clone->getName();

                // Определяем правильный statePath в зависимости от драйвера
                $statePath = $this->resolveStatePath($attribute, $locale);

                $clone
                    ->name("{$attribute}_{$locale}")
                    ->statePath($statePath);

                $fields[] = $clone;
            }

            $tabs[] = Tab::make($label)->key("tab_{$locale}")->schema($fields);
        }

        return $tabs;
    }

    protected function resolveStatePath(string $attribute, string $locale): string
    {
        // Получаем Livewire компонент и модель
        $livewire = $this->getLivewire();
        $record = $livewire->getRecord();

        if (!$record || !method_exists($record, 'driver')) {
            // Fallback для простых случаев
            return "{$attribute}.{$locale}";
        }

        try {
            $driver = $record->driver($attribute);
            $baseLocale = $driver->resolveBaseLocale($record);

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
                return "{$attribute}.{$locale}"; // title.en
            }
        } catch (\Exception $e) {
            // Fallback при любых ошибках
            return "{$attribute}.{$locale}";
        }
    }
}
```

**Преимущества:**
- ✅ Работает со ВСЕМИ драйверами (JsonColumnDriver, HybridColumnDriver, ExtraOnlyDriver)
- ✅ Быстрый (как TranslatableTabs) - нативные механизмы Filament
- ✅ Не нужны JS события
- ✅ Builder/EditorJS переключаются мгновенно

**Недостатки:**
- ⚠️ Нужен доступ к модели в `getDefaultChildComponents()`
- ⚠️ Может быть проблема на Create страницах (нет модели)

**Решение для Create страниц:**
```php
protected function resolveStatePath(string $attribute, string $locale): string
{
    $livewire = $this->getLivewire();
    $record = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;

    // Если нет модели (Create страница) - используем конфиг
    if (!$record) {
        $defaultDriver = config('translatable.default_driver', 'json');
        $baseLocale = config('app.locale', 'ru');

        if ($defaultDriver === 'hybrid') {
            return $locale === $baseLocale ? $attribute : "extra.{$locale}.{$attribute}";
        } elseif ($defaultDriver === 'extra_only') {
            return "extra.{$locale}.{$attribute}";
        }

        return "{$attribute}.{$locale}";
    }

    // ... остальной код как выше
}
```

---

**Решение 2 (текущее): Доделать JS обновление для Builder/EditorJS в LanguageTabs**

Оставить текущую архитектуру LanguageTabs но доделать JS обновление.

**Преимущества:**
- ✅ Уже работает с драйверами через `getTranslations()`
- ✅ Универсальный

**Недостатки:**
- ❌ Медленный (нужны JS события)
- ❌ Сложный код с поиском EditorJS в DOM
- ❌ Может сломаться при изменении Filament

---

**Рекомендация:**

Попробовать **Решение 1** - доработать как TranslatableTabs с поддержкой драйверов. Это даст:
- Мгновенное переключение Builder/EditorJS (как у Abdulmajeed)
- Поддержку всех драйверов (title/description будут работать)
- Чистый код без JS костылей
