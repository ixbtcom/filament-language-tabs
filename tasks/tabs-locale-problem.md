# Проблема: `currentLocale` не обновляется при переключении табов

> **Дата**: 2025-10-10
> **Статус**: Критическая проблема найдена
> **Компонент**: LanguageTabs.php

---

## Проблема

При переключении языковых табов:
- **Симптом**: EditorJS и другие компоненты не обновляются
- **Причина**: `$this->currentLocale` НИКОГДА не обновляется при переключении
- **Почему**: `Tabs` НЕ имеет `HasState` trait, методы `afterStateHydrated/afterStateUpdated` НЕ СРАБАТЫВАЮТ

---

## Анализ кода

### Текущая реализация в LanguageTabs.php (строки 84-102)

```php
$tabsComponent = Tabs::make()
    ->key('language_tabs')
    ->afterStateHydrated(function (Tabs $component, $state) use ($locales): void {
        // ❌ ЭТОТ КОЛБЕК НИКОГДА НЕ ВЫЗЫВАЕТСЯ!
        if ($this->currentLocale === null) {
            $index = is_numeric($state) ? (int) $state : 1;
            $this->currentLocale = $locales[$index - 1] ?? ($locales[0] ?? null);
        }
    })
    ->afterStateUpdated(function (Tabs $component, $state) use ($locales): void {
        // ❌ ЭТОТ КОЛБЕК НИКОГДА НЕ ВЫЗЫВАЕТСЯ!
        $index = is_numeric($state) ? (int) $state : 1;
        $locale = $locales[$index - 1] ?? null;

        if (is_string($locale)) {
            $this->currentLocale = $locale;
        }
    })
    ->schema($tabs);
```

**Почему не работает:**

1. `Tabs` component наследуется от `Component`, НЕ использует `HasState` trait
2. Методы `afterStateHydrated()` и `afterStateUpdated()` определены в `HasState` trait
3. Когда мы вызываем эти методы на `Tabs`, они просто **игнорируются** (нет реализации)
4. Tabs работает через Alpine `tab` переменную, а не через Livewire state

**Доказательство:**

`/Users/mikhailpanyushkin/code/xcom/libs/filament/packages/schemas/src/Components/Tabs.php`:
- Нет `use HasState`
- Нет методов `afterStateHydrated/afterStateUpdated`
- В Blade: `x-data="tabsSchemaComponent({ activeTab: @js($activeTab), ... })"` - Alpine управляет табами

---

## Два режима работы Tabs

### Режим 1: Alpine (текущий в LanguageTabs)

**tabs.blade.php строки 13-126:**

```blade
<div x-data="tabsSchemaComponent({
    activeTab: @js($activeTab),
    tab: @js(null),
    ...
})">
    <!-- Tabs переключаются через Alpine x-on:click="tab = 'tab_ru'" -->
    <!-- НЕТ связи с Livewire state -->
</div>
```

**Проблема**: Livewire компонент НЕ ЗНАЕТ о переключении табов!

### Режим 2: Livewire Property

**tabs.blade.php строки 127-194:**

```blade
@php
    $activeTab = strval($this->{$livewireProperty});
@endphp

<x-filament::tabs.item
    :wire:click="'$set(\'' . $livewireProperty . '\', \'' . $tabKey . '\')'"
>
```

**Решение**: При клике вызывается Livewire `$set()`, обновляется property, срабатывают хуки!

---

## Решение

### Вариант 1: Использовать Livewire Property для Tabs

Сделать как в Filament делает - использовать livewire property для хранения активного таба.

**Проблема**: LanguageTabs - это Schema Component, не Livewire компонент. Нужно добавить property в родительский Livewire (форму).

**Реализация:**

1. Добавить метод для задания livewire property в LanguageTabs:

```php
protected ?string $tabsLivewireProperty = null;

public function tabsLivewireProperty(string $property): static
{
    $this->tabsLivewireProperty = $property;
    return $this;
}
```

2. Передать его в Tabs:

```php
$tabsComponent = Tabs::make()
    ->key('language_tabs')
    ->livewireProperty($this->tabsLivewireProperty)
    ->schema($tabs);
```

3. В форме определить property и слушать изменения:

```php
// В Livewire компоненте (EditPage, CreatePage)
public string $activeLanguageTab = 'tab_ru';

protected function getListeners(): array
{
    return [
        'updated:activeLanguageTab' => 'handleLanguageTabChange',
    ];
}

public function handleLanguageTabChange()
{
    // Здесь можно триггерить обновление
}
```

**Минусы:**
- Требует изменения каждого Livewire компонента где используется LanguageTabs
- Усложняет API
- Не работает "из коробки"

---

### Вариант 2: Использовать JavaScript событие для синхронизации

Использовать Alpine событие при переключении таба для обновления currentLocale.

**Реализация:**

1. Добавить JavaScript событие при изменении таба:

```php
$tabsComponent = Tabs::make()
    ->key('language_tabs')
    ->extraAlpineAttributes([
        'x-effect' => "
            // Отслеживаем изменение Alpine 'tab' переменной
            \$watch('tab', (value) => {
                // Отправляем событие Livewire с индексом таба
                const tabElement = document.querySelector('[x-data*=\"tabsSchemaComponent\"]');
                const tabsData = JSON.parse(tabElement.querySelector('[x-ref=\"tabsData\"]').value);
                const tabIndex = tabsData.indexOf(value);

                // Вызываем Livewire метод для обновления locale
                \$wire.call('updateLanguageTabLocale', tabIndex + 1);
            });
        ",
    ])
    ->schema($tabs);
```

2. Добавить метод в LanguageTabs view component:

```php
// В resources/views/forms/components/language-tabs.blade.php
@php
    $locales = $component->resolveLocales();
@endphp

<div
    x-data="{
        updateLocale(tabIndex) {
            const locales = @js($locales);
            const locale = locales[tabIndex - 1] ?? locales[0];

            // Отправляем событие Livewire
            $wire.set('currentLocale', locale);
        }
    }"
>
    {{ $getChildSchema() }}
</div>
```

**Минусы:**
- currentLocale должна быть Livewire property
- LanguageTabs - это Schema Component, не Livewire

---

### Вариант 3: Передавать locale явно в каждый Builder (РЕКОМЕНДУЕМОЕ)

Не полагаться на `$this->currentLocale`, а передавать locale явно в каждый компонент.

**Ключевой инсайт:**
- Каждый Builder уже ЗНАЕТ свою локаль через closure `use ($locale)`
- Проблема НЕ в определении локали, а в том что **данные не обновляются визуально**

**Истинная проблема:**

1. Builder для RU создается с `$locale = 'ru'` в closure
2. Builder для EN создается с `$locale = 'en'` в closure
3. При переключении таба:
   - Tab RU скрывается (`x-show="false"`)
   - Tab EN показывается (`x-show="true"`)
4. **НО**: Builder EN не знает что он стал видимым!
5. EditorJS внутри Builder EN не получает сигнал обновиться

**Решение: Добавить Alpine watcher на видимость таба**

В `tabfields()` метод, при создании Builder/Repeater:

```php
if ($clone instanceof Builder) {
    // ... существующий код ...

    // Добавляем Alpine атрибут для отслеживания видимости
    $clone->extraAlpineAttributes([
        'x-effect' => "
            // Отслеживаем когда этот таб становится видимым
            if (tab === 'tab_{$locale}') {
                // Таб стал активным - обновляем компонент
                \$nextTick(() => {
                    // Найти все EditorJS внутри и вызвать render
                    const editorElements = \$el.querySelectorAll('[x-data*=\"editorjs\"]');
                    editorElements.forEach(editorEl => {
                        const alpineData = Alpine.\$data(editorEl);
                        if (alpineData && alpineData.instance) {
                            // Триггерим обновление EditorJS
                            alpineData.instance.render(alpineData.state || { blocks: [] });
                        }
                    });
                });
            }
        ",
    ]);
}
```

**Плюсы:**
- ✅ Работает "из коробки"
- ✅ Не требует изменений в Livewire компонентах
- ✅ Локаль определяется правильно (из closure)
- ✅ Обновляет EditorJS при показе таба

**Минусы:**
- ⚠️ JavaScript в PHP коде (но Filament так делает)
- ⚠️ Зависит от внутренней структуры Alpine/EditorJS

---

### Вариант 4: Использовать wire:key для форсирования пересоздания

Добавить динамический `wire:key` на Builder, который зависит от активного таба.

**Проблема**: Нужно знать активный таб, а он хранится в Alpine, не в Livewire.

---

### Вариант 5: Вызывать callAfterStateUpdated() при показе таба (ПРОСТЕЙШЕЕ)

Использовать Alpine watcher для вызова `callAfterStateUpdated()` через Livewire.

**Реализация:**

1. Добавить в LanguageTabs метод для обновления Builder'а:

```php
// В LanguageTabs.php

public function handleTabSwitch(string $locale): void
{
    $this->currentLocale = $locale;

    // Триггерим обновление всех Builder'ов для этой локали
    $this->refreshBuilderComponents();
}
```

2. Добавить Alpine watcher на Tabs:

```php
$tabsComponent = Tabs::make()
    ->key('language_tabs')
    ->extraAlpineAttributes([
        '@tab-changed.window' => "
            // Получаем индекс таба из события
            const tabKey = \$event.detail.tab;
            const locales = @js($locales);
            const localeIndex = parseInt(tabKey.replace('tab_', '')) - 1;
            const locale = locales[localeIndex];

            // Вызываем Livewire метод (если доступен)
            if (\$wire.handleTabSwitch) {
                \$wire.handleTabSwitch(locale);
            }
        ",
    ])
    ->schema($tabs);
```

3. В `resources/js/components/tabs.js` (Alpine компонент Tabs):

```js
// При изменении таба
this.tab = newTab;

// Отправляем событие
window.dispatchEvent(new CustomEvent('tab-changed', {
    detail: { tab: newTab }
}));
```

**Проблема**: Требует изменения Alpine компонента Tabs, который находится в Filament core.

---

## Рекомендуемое решение: Вариант 3 (Alpine watcher на видимость)

Самое простое и работающее решение:

### Изменения в LanguageTabs.php

**В методе `tabfields()` (строка 132), после строки 155:**

```php
if ($clone instanceof Builder) {
    $componentKey = "language_tabs.{$componentName}." . spl_object_id($clone);
    $clone->key($componentKey);
    $this->hasBuilders = true;
    $clone->meta('language_tabs', [
        'attribute' => $base,
        'locale' => $locale,
    ]);
    $this->prepareBuilderForLocale($clone, $base, $locale);

    // ✅ ДОБАВИТЬ: Alpine watcher для обновления при показе таба
    $clone->extraAlpineAttributes([
        'x-data' => "{ locale: '{$locale}' }",
        'x-effect' => <<<JS
            // Проверяем есть ли родительский компонент с табами
            const tabsComponent = \$el.closest('[x-data*="tabsSchemaComponent"]');
            if (!tabsComponent) return;

            const tabsData = Alpine.\$data(tabsComponent);
            if (!tabsData || !tabsData.tab) return;

            // Если наш таб активен - обновляем EditorJS
            if (tabsData.tab === 'tab_{$locale}') {
                \$nextTick(() => {
                    // Найти все EditorJS внутри этого Builder
                    const editorElements = \$el.querySelectorAll('[x-data*="editorjs"]');
                    editorElements.forEach(editorEl => {
                        const alpineData = Alpine.\$data(editorEl);
                        if (alpineData?.instance?.isReady) {
                            const state = alpineData.state || { blocks: [] };
                            alpineData.instance.render(state).catch(console.error);
                        }
                    });
                });
            }
        JS,
    ]);
}
```

### Почему это работает:

1. ✅ **Каждый Builder знает свою локаль** через closure `use ($locale)`
2. ✅ **Alpine watcher следит за активным табом** через `tabsData.tab`
3. ✅ **При активации таба** - находит EditorJS и вызывает `render()`
4. ✅ **Работает с существующим кодом** - не требует изменений в Livewire
5. ✅ **Локаль определяется правильно** - из переменной `$locale` в closure

### Поток работы:

```
[User кликает Tab EN]
  ↓
[Alpine: tabsData.tab = 'tab_en']
  ↓
[Alpine watcher в Builder срабатывает]
  ↓
[Проверка: tabsData.tab === 'tab_en'? Да!]
  ↓
[Поиск всех EditorJS внутри Builder]
  ↓
[Вызов editorjs.instance.render(state)]
  ↓
[EditorJS показывает контент EN] ✅
```

---

## Тестирование

После применения:

1. Открыть запись с данными в RU и EN
2. Переключиться RU → EN
3. EditorJS должен обновиться с контентом EN ✅
4. Переключиться EN → RU
5. EditorJS должен показать контент RU ✅
6. Открыть console - не должно быть ошибок ✅

---

## Альтернатива: Простой dispatch Livewire события

Если Alpine watcher не сработает, можно использовать Livewire события:

```php
// В prepareBuilderForLocale после установки state
$builder->afterStateHydrated(function (Builder $component, ?array $state) use ($existingHydrated, $attribute, $locale): void {
    // ... существующий код ...

    $component->state(is_array($localeData) ? $localeData : []);

    // ✅ Dispatch Livewire событие для обновления EditorJS
    $component->getLivewire()->dispatch(
        'builder-locale-updated',
        statePath: $component->getStatePath(),
        locale: $locale
    );
});
```

Затем в editorjs.js слушать это событие:

```js
// В init()
Livewire.on('builder-locale-updated', (data) => {
    if (data.statePath.includes(this.statePath)) {
        this.instance.render(this.state || { blocks: [] });
    }
});
```

**Минусы:**
- Требует изменения editorjs.js
- Может срабатывать слишком часто
