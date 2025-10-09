# Проблема с Builder полем в LanguageTabs

> **Обновлено**: Документ обновлен с учетом актуальных механизмов Filament 4.x
>
> **Источник**: `/Users/mikhailpanyushkin/code/xcom/libs/filament` и `/Users/mikhailpanyushkin/code/xcom/libs/forms`

## Важно: Актуальные механизмы Filament 4.x

### Методы для работы с состоянием (HasState.php)

**Хуки состояния:**
- `afterStateHydrated(?Closure $callback)` - вызывается после загрузки состояния из модели
- `afterStateUpdated(?Closure $callback)` - вызывается после обновления состояния (можно добавлять несколько)
- `afterStateUpdatedJs(string | Closure | null $js)` - JavaScript хук через `$wire.watch(statePath, callback)`
- `clearAfterStateUpdatedHooks()` - очистка всех хуков afterStateUpdated

**Устаревшие методы (удалены в v4.x):**
- ❌ `reactive()` - удален
- ❌ `live()` - удален

**Механизм предотвращения дублирования:**
```php
// Filament автоматически предотвращает повторные вызовы одного хука с тем же состоянием
$runId = spl_object_id($callback) . md5(json_encode($this->getState()));
if (store($this)->has('executedAfterStateUpdatedCallbacks', iKey: $runId)) {
    continue;
}
```

**Пробрасывание событий:**
- `callAfterStateUpdated(bool $shouldBubbleToParents = true)` - вызывает хуки и может пробросить событие в родительский компонент
- Builder/Repeater автоматически вызывают этот метод после всех операций (add, delete, edit, reorder)

## Диагностика проблемы

### Описание симптомов

При использовании поля Builder (или Repeater) внутри компонента LanguageTabs статус языка не обновляется корректно. Данные из вложенных полей builder не синхронизируются с JSON-переводами, что приводит к потере данных или некорректному отображению состояния.

### Корневая причина

Анализ кода `src/Forms/Components/LanguageTabs.php:134-173` показывает следующую проблему:

**Метод `prepareFieldForLocale()` применяется только к простым Field компонентам**

```php
// src/Forms/Components/LanguageTabs.php:93-94
if ($clone instanceof Field) {
    $this->prepareFieldForLocale($clone, $base, $locale);
}
```

**Почему это проблема:**

1. **Builder/Repeater - это контейнерные компоненты**, они содержат массив дочерних элементов (blocks/items)
2. **prepareFieldForLocale настраивает хуки только для верхнего уровня** (`afterStateHydrated`, `afterStateUpdated`)
3. **Дочерние элементы Builder не получают эти хуки** и не знают о механизме синхронизации с JSON-переводами
4. **Builder управляет состоянием своих items напрямую**, минуя механизм, настроенный в `prepareFieldForLocale`

## Поток данных (трассировка)

### Текущая реализация для простых полей

```
[Model with JSON]
    ↓ getTranslations('attribute')
[afterStateHydrated] → распределяет данные по локалям
    ↓
[Field State] → пользователь редактирует
    ↓
[afterStateUpdated] → собирает данные обратно в JSON
    ↓
[Model JSON updated]
```

### Проблема с Builder

```
[Model with JSON]
    ↓ getTranslations('builder_field')
[afterStateHydrated на Builder] → устанавливает массив items для локали
    ↓
[Builder Field State]
    └── Item 1 (Block Type A)
        ├── Subfield 1 ❌ НЕТ ХУКОВ
        └── Subfield 2 ❌ НЕТ ХУКОВ
    └── Item 2 (Block Type B)
        ├── Subfield 1 ❌ НЕТ ХУКОВ
        └── Subfield 2 ❌ НЕТ ХУКОВ
    ↓ Пользователь изменяет Subfield
    ↓ Builder обновляет свой state напрямую
    ❌ afterStateUpdated НЕ ВЫЗЫВАЕТСЯ для subfields
    ❌ Данные не синхронизируются обратно в JSON
```

### Дополнительная сложность с Builder

Builder в Filament имеет специфическую структуру данных:

```php
// Структура данных Builder
[
    [
        'type' => 'heading',  // тип блока
        'data' => [           // данные блока
            'content' => 'Some text',
            'level' => 1
        ]
    ],
    [
        'type' => 'paragraph',
        'data' => [
            'content' => 'Another text'
        ]
    ]
]
```

Каждый блок содержит:
- `type` - идентификатор типа блока
- `data` - ключ-значение данных блока

**Проблема**: Даже если мы настроим хуки для Builder, они будут работать только на уровне массива items, а не на уровне отдельных полей внутри `data` каждого блока.

### Детали реализации Builder и Repeater из исходников Filament

**Источник**: `/Users/mikhailpanyushkin/code/xcom/libs/forms/src/Components/`

#### Builder (Builder.php:110-122, 911-925)

```php
// Хук afterStateHydrated в setUp()
$this->afterStateHydrated(static function (Builder $component, ?array $rawState): void {
    $items = [];
    foreach ($rawState ?? [] as $itemData) {
        if ($uuid = $component->generateUuid()) {
            $items[$uuid] = $itemData;
        } else {
            $items[] = $itemData;
        }
    }
    $component->rawState($items);
});

// Метод getItems() возвращает Schema для каждого item
public function getItems(): array
{
    return collect($this->getRawState())
        ->filter(fn (array $itemData): bool => filled($itemData['type'] ?? null) && $this->hasBlock($itemData['type']))
        ->map(
            fn (array $itemData, $itemIndex): Schema => $this
                ->getBlock($itemData['type'])
                ->getChildSchema()
                ->statePath("{$itemIndex}.data")  // ВАЖНО: statePath включает .data
                ->constantState($itemData['data'] ?? [])
                ->inlineLabel(false)
                ->getClone(),
        )
        ->all();
}
```

**Ключевые моменты**:
- Builder использует `statePath("{$itemIndex}.data")` для каждого блока
- `getBlocks()` возвращает массив Block компонентов через `getChildSchema()->getComponents()`
- Каждый Block имеет метод `getChildSchema()`, который содержит Schema с полями блока
- `mutateDehydratedStateUsing` преобразует данные в `array_values()` при сохранении

#### Repeater (Repeater.php:124-159, 819-838)

```php
// Хук afterStateHydrated в setUp()
$this->afterStateHydrated(static function (Repeater $component, ?array $rawState): void {
    // ... обработка hydratedDefaultState ...

    $items = [];
    $simpleField = $component->getSimpleField();

    foreach ($rawState ?? [] as $itemData) {
        if ($simpleField) {
            $itemData = [$simpleField->getName() => $itemData];
        }

        if ($uuid = $component->generateUuid()) {
            $items[$uuid] = $itemData;
        } else {
            $items[] = $itemData;
        }
    }

    $component->rawState($items);
});

// Метод getItems()
public function getItems(): array
{
    $relationship = $this->getRelationship();
    $records = $relationship ? $this->getCachedExistingRecords() : null;

    $items = [];

    foreach ($this->getRawState() ?? [] as $itemKey => $itemData) {
        $items[$itemKey] = $this
            ->getChildSchema()
            ->statePath($itemKey)  // ВАЖНО: statePath = ключ элемента
            ->constantState(((! ($relationship && $records->has($itemKey))) && is_array($itemData)) ? $itemData : null)
            ->model($relationship ? $records[$itemKey] ?? $this->getRelatedModel() : null)
            ->inlineLabel(false)
            ->getClone();
    }

    return $items;
}
```

**Ключевые моменты**:
- Repeater использует `statePath($itemKey)` для каждого элемента (без `.data`)
- `getChildSchema()` возвращает единую Schema, которая клонируется для каждого item
- Поддерживает relationships с моделями
- `mutateDehydratedStateUsing` также делает `array_values()`

### Критическое различие между Builder и Repeater

| Компонент | StatePath для item | Структура данных |
|-----------|-------------------|------------------|
| Builder | `{itemIndex}.data` | `[['type' => '...', 'data' => [...]]]` |
| Repeater | `{itemKey}` | `[[field1 => val1, field2 => val2]]` |

**Вывод**: При обработке Builder нужно учитывать дополнительный уровень вложенности через `.data`.

## Анализ кода

### Текущая реализация tabfields()

```php
// src/Forms/Components/LanguageTabs.php:78-105
protected function tabfields(array $components, string $locale): array
{
    $required_locales = config('filament-language-tabs.required_locales', []);
    $required_locales = array_unique($required_locales);

    $tabfields = [];
    foreach ($components as $component) {
        $clone = clone $component;  // ❌ Shallow clone
        $base = $clone->getName();

        $componentName = "{$base}_{$locale}";
        $statePath = "{$base}.{$locale}";
        $clone->name($componentName);
        $clone->statePath($statePath);

        // ✅ Применяется только к простым Field
        if ($clone instanceof Field) {
            $this->prepareFieldForLocale($clone, $base, $locale);
        }

        // ❌ Контейнерные компоненты (Builder, Repeater) НЕ ОБРАБАТЫВАЮТСЯ

        if (!in_array($locale, $required_locales, true)) {
            $clone->required(false);
        }

        $tabfields[] = $clone;
    }

    return $tabfields;
}
```

### Проблема с clone

```php
$clone = clone $component;
```

**Shallow clone** не клонирует глубоко вложенные объекты. Для Builder это означает, что все клоны будут ссылаться на одни и те же объекты blocks.

## Решения

### Решение 1: Рекурсивная обработка контейнерных компонентов (РЕКОМЕНДУЕМОЕ)

**Подход**: Определить контейнерные компоненты и рекурсивно применить `prepareFieldForLocale` к их дочерним элементам.

#### Шаг 1: Добавить метод для определения контейнерных компонентов

```php
// src/Forms/Components/LanguageTabs.php

use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Repeater;

/**
 * Проверяет, является ли компонент контейнером с дочерними элементами
 */
protected function isContainerComponent(Component $component): bool
{
    return $component instanceof Builder
        || $component instanceof Repeater
        || method_exists($component, 'getChildComponents');
}
```

#### Шаг 2: Добавить метод для рекурсивной настройки вложенных полей

```php
/**
 * Рекурсивно применяет prepareFieldForLocale ко всем полям,
 * включая вложенные в контейнеры
 */
protected function prepareComponentForLocale(
    Component $component,
    string $baseAttribute,
    string $locale
): void {
    // Если это простое поле - настраиваем хуки
    if ($component instanceof Field) {
        $this->prepareFieldForLocale($component, $baseAttribute, $locale);
    }

    // Если это контейнер - обрабатываем дочерние элементы
    if ($this->isContainerComponent($component)) {
        $this->prepareContainerChildrenForLocale($component, $baseAttribute, $locale);
    }
}

/**
 * Обрабатывает дочерние элементы контейнерного компонента
 */
protected function prepareContainerChildrenForLocale(
    Component $container,
    string $baseAttribute,
    string $locale
): void {
    if ($container instanceof Builder) {
        // Builder содержит blocks, каждый block содержит schema
        foreach ($container->getBlocks() as $block) {
            $blockSchema = $block->getSchema();
            foreach ($blockSchema as $childComponent) {
                // Для дочерних элементов используем их собственное имя
                $childBase = $childComponent->getName();
                $this->prepareComponentForLocale($childComponent, $childBase, $locale);
            }
        }
    } elseif ($container instanceof Repeater) {
        // Repeater содержит schema напрямую
        $schema = $container->getChildComponents();
        foreach ($schema as $childComponent) {
            $childBase = $childComponent->getName();
            $this->prepareComponentForLocale($childComponent, $childBase, $locale);
        }
    } elseif (method_exists($container, 'getChildComponents')) {
        // Общий случай для других контейнеров
        foreach ($container->getChildComponents() as $childComponent) {
            $childBase = $childComponent->getName();
            $this->prepareComponentForLocale($childComponent, $childBase, $locale);
        }
    }
}
```

#### Шаг 3: Обновить метод tabfields()

```php
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

        // ✅ ИЗМЕНЕНО: Используем новый рекурсивный метод
        $this->prepareComponentForLocale($clone, $base, $locale);

        if (!in_array($locale, $required_locales, true)) {
            $clone->required(false);
        }

        $tabfields[] = $clone;
    }

    return $tabfields;
}
```

### Решение 2: Специальная обработка Builder через afterStateHydrated/Updated контейнера

**Подход**: Настроить хуки на уровне Builder, которые будут обрабатывать весь массив items.

#### Реализация

```php
protected function prepareComponentForLocale(
    Component $component,
    string $baseAttribute,
    string $locale
): void {
    if ($component instanceof Field) {
        $this->prepareFieldForLocale($component, $baseAttribute, $locale);
    }

    // Специальная обработка для Builder
    if ($component instanceof Builder) {
        $this->prepareBuilderForLocale($component, $baseAttribute, $locale);
    }
}

protected function prepareBuilderForLocale(
    Builder $builder,
    string $attribute,
    string $locale
): void {
    $builder->afterStateHydrated(function (Builder $component) use ($attribute, $locale): void {
        $get = $component->makeGetUtility();
        $set = $component->makeSetUtility();

        $attributeState = $get($attribute);

        if (!is_array($attributeState)) {
            $attributeState = $this->normaliseAttributeState($component, $attribute, $attributeState);
            $set($attribute, $attributeState, shouldCallUpdatedHooks: false);
        }

        // Получаем данные для текущей локали
        $localeData = Arr::get($attributeState, $locale);

        // Builder ожидает массив items
        $component->state($localeData ?? []);
    });

    $builder->afterStateUpdated(function (Builder $component, $state) use ($attribute, $locale): void {
        $get = $component->makeGetUtility();
        $set = $component->makeSetUtility();

        $translations = $get($attribute);

        if (!is_array($translations)) {
            $translations = [];
        }

        // Сохраняем весь массив items для локали
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
```

### Решение 3: Hybrid подход (НАИБОЛЕЕ НАДЕЖНОЕ)

Комбинация Решения 1 и Решения 2:
1. Специальная обработка для Builder/Repeater на верхнем уровне (как в Решении 2)
2. Рекурсивная обработка дочерних элементов (как в Решении 1)

Это обеспечит корректную работу как на уровне контейнера, так и на уровне вложенных полей.

```php
protected function prepareComponentForLocale(
    Component $component,
    string $baseAttribute,
    string $locale
): void {
    // Простые поля
    if ($component instanceof Field && !$this->isContainerComponent($component)) {
        $this->prepareFieldForLocale($component, $baseAttribute, $locale);
        return;
    }

    // Builder/Repeater - специальная обработка
    if ($component instanceof Builder) {
        $this->prepareBuilderForLocale($component, $baseAttribute, $locale);
        // И рекурсивно обрабатываем дочерние элементы
        $this->prepareContainerChildrenForLocale($component, $baseAttribute, $locale);
        return;
    }

    if ($component instanceof Repeater) {
        $this->prepareRepeaterForLocale($component, $baseAttribute, $locale);
        $this->prepareContainerChildrenForLocale($component, $baseAttribute, $locale);
        return;
    }

    // Другие контейнеры
    if ($this->isContainerComponent($component)) {
        $this->prepareContainerChildrenForLocale($component, $baseAttribute, $locale);
    }
}
```

## Дополнительные проблемы и их решения

### Проблема 1: Shallow clone

**Проблема**: `clone $component` создает поверхностную копию, что может привести к общим ссылкам на вложенные объекты.

**Решение**: Для Builder/Repeater нужно явно клонировать blocks/schema:

```php
protected function deepCloneComponent(Component $component): Component
{
    $clone = clone $component;

    if ($component instanceof Builder) {
        // Клонируем blocks
        $clonedBlocks = [];
        foreach ($component->getBlocks() as $key => $block) {
            $clonedBlocks[$key] = clone $block;
        }
        $clone->blocks($clonedBlocks);
    }

    return $clone;
}
```

Использование:
```php
// В методе tabfields()
$clone = $this->deepCloneComponent($component);
```

### Проблема 2: StatePath для вложенных полей

**Проблема**: При работе с Builder вложенные поля имеют сложный statePath типа `builder_field.0.content` (где 0 - индекс item).

**Решение**: Убедиться, что базовый statePath настроен правильно для контейнера, а вложенные поля будут использовать относительные пути.

```php
protected function prepareContainerChildrenForLocale(
    Component $container,
    string $baseAttribute,
    string $locale
): void {
    if ($container instanceof Builder) {
        foreach ($container->getBlocks() as $block) {
            $blockSchema = $block->getSchema();
            foreach ($blockSchema as $childComponent) {
                // НЕ изменяем statePath дочерних элементов
                // Builder сам управляет путями своих дочерних элементов
                $childBase = $childComponent->getName();

                // Применяем только базовые хуки без изменения statePath
                if ($childComponent instanceof Field) {
                    $this->prepareFieldForLocaleWithoutStatePath(
                        $childComponent,
                        $childBase,
                        $locale
                    );
                }
            }
        }
    }
}
```

### Проблема 3: Обновление вложенных полей не триггерит родительский Builder

**Проблема**: Когда вложенное поле обновляется, Builder может не знать об этом.

**Решение**: В Filament 4.x это решается автоматически через механизм `callAfterStateUpdated()`. Builder автоматически вызывает этот метод после всех своих операций (add, delete, edit). Дополнительная настройка не требуется.

**Примечание**: Методы `reactive()` и `live()` устарели и удалены в Filament 4.x. Вместо этого используется:
- `afterStateUpdated(Closure $callback)` - PHP хук
- `afterStateUpdatedJs(string $js)` - JavaScript хук через `$wire.watch(statePath, callback)`

## Рекомендации по тестированию

### Тест-кейс 1: Builder с простыми полями

```php
it('synchronizes builder field with translations', function () {
    Config::set('filament-language-tabs', [
        'default_locales' => ['en', 'de'],
        'required_locales' => ['en'],
    ]);

    livewire(BuilderFormTester::class)
        ->fillForm([
            'language_tabs.tab_en.content_en' => [
                [
                    'type' => 'heading',
                    'data' => ['content' => 'English Heading']
                ]
            ],
            'language_tabs.tab_de.content_de' => [
                [
                    'type' => 'heading',
                    'data' => ['content' => 'German Heading']
                ]
            ]
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    // Проверяем, что данные сохранились как JSON
    $record = Post::first();
    expect($record->content)->toBe([
        'en' => [
            ['type' => 'heading', 'data' => ['content' => 'English Heading']]
        ],
        'de' => [
            ['type' => 'heading', 'data' => ['content' => 'German Heading']]
        ]
    ]);
});
```

### Тест-кейс 2: Builder с вложенными Repeater

```php
it('synchronizes nested repeater inside builder', function () {
    // Тест для сложной вложенной структуры
    // Builder -> Block -> Repeater -> Fields
});
```

### Тест-кейс 3: Обновление существующих данных

```php
it('hydrates existing builder data correctly', function () {
    $post = Post::create([
        'content' => [
            'en' => [
                ['type' => 'heading', 'data' => ['content' => 'Old Heading']]
            ]
        ]
    ]);

    livewire(BuilderFormTester::class, ['record' => $post->id])
        ->assertFormSet([
            'language_tabs.tab_en.content_en.0.data.content' => 'Old Heading'
        ]);
});
```

## План реализации

### Этап 1: Базовая поддержка Builder (2-3 часа)
1. Добавить use statements для Builder/Repeater
2. Реализовать `isContainerComponent()`
3. Реализовать `prepareBuilderForLocale()` (Решение 2)
4. Обновить `tabfields()` для использования нового метода
5. Написать базовый тест

### Этап 2: Рекурсивная обработка (3-4 часа)
1. Реализовать `prepareComponentForLocale()` (рекурсивный)
2. Реализовать `prepareContainerChildrenForLocale()`
3. Добавить поддержку Repeater
4. Написать тесты для вложенных структур

### Этап 3: Deep clone и edge cases (2-3 часа)
1. Реализовать `deepCloneComponent()`
2. Обработать edge cases (пустые данные, null, missing locales)
3. Добавить тесты для edge cases
4. Обновить документацию

### Этап 4: Оптимизация и рефакторинг (1-2 часа)
1. Оптимизировать производительность для больших форм
2. Рефакторинг для читаемости кода
3. Добавить PHPDoc комментарии
4. Code review

## Потенциальные проблемы

### 1. Производительность
Рекурсивная обработка больших форм может быть медленной. Решение: кэширование обработанных компонентов.

### 2. Конфликты с пользовательскими хуками
Если пользователь уже определил afterStateHydrated/Updated на Builder, наши хуки могут конфликтовать. Решение: проверять существующие хуки или использовать более высокий приоритет.

### 3. Сложные вложенные структуры
Builder -> Repeater -> Builder. Решение: тщательное тестирование рекурсивной логики.

### 4. Обратная совместимость
Изменения могут сломать существующий код пользователей. Решение: мажорная версия или feature flag.

## Альтернативные подходы

### Подход А: Wrapper компонент для Builder

Создать специальный `TranslatableBuilder` компонент:

```php
TranslatableBuilder::make('content')
    ->blocks([...])
```

Внутри он будет обрабатывать переводы автоматически.

**Плюсы**:
- Явный API
- Не ломает существующий код
- Проще в реализации

**Минусы**:
- Дополнительный API для изучения
- Дублирование кода

### Подход Б: Использовать события Filament

Подписаться на события изменения форм и обрабатывать Builder специально.

**Плюсы**:
- Не требует изменения существующего кода
- Гибкость

**Минусы**:
- Сложнее отладка
- Может быть менее производительным

## Финальное рекомендуемое решение

После изучения исходного кода Filament Forms, предлагается следующий подход:

### Решение: Двухуровневая обработка

1. **Уровень контейнера** (Builder/Repeater целиком):
   - Настроить хуки `afterStateHydrated`/`afterStateUpdated` на сам Builder/Repeater
   - Эти хуки будут синхронизировать весь массив items с JSON-переводами
   - Важно: Builder требует специальной обработки структуры `{'type': '...', 'data': {...}}`

2. **Уровень дочерних полей** (НЕ ТРЕБУЕТСЯ для базового решения):
   - Поля внутри Builder blocks получают данные через Livewire wire:model
   - Filament автоматически синхронизирует изменения через `callAfterStateUpdated()` на контейнере
   - Рекурсивная обработка нужна только для nested Builder/Repeater внутри Builder/Repeater

### Реализация

```php
// src/Forms/Components/LanguageTabs.php

use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Arr;

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

        // Обработка разных типов компонентов
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

protected function prepareBuilderForLocale(
    Builder $builder,
    string $attribute,
    string $locale
): void {
    $builder->afterStateHydrated(function (Builder $component) use ($attribute, $locale): void {
        $get = $component->makeGetUtility();
        $set = $component->makeSetUtility();

        $attributeState = $get($attribute);

        // Нормализуем состояние если нужно
        if (!is_array($attributeState)) {
            $attributeState = $this->normaliseAttributeState($component, $attribute, $attributeState);
            $set($attribute, $attributeState, shouldCallUpdatedHooks: false);
        }

        // Получаем данные для текущей локали
        $localeData = Arr::get($attributeState, $locale, []);

        // Builder ожидает массив items с структурой [['type' => '...', 'data' => [...]]]
        $component->state($localeData);
    });

    $builder->afterStateUpdated(function (Builder $component, $state) use ($attribute, $locale): void {
        $get = $component->makeGetUtility();
        $set = $component->makeSetUtility();

        $translations = $get($attribute);

        if (!is_array($translations)) {
            $translations = [];
        }

        // Сохраняем весь массив items для локали (включая type и data для каждого)
        $translations[$locale] = $state ?? [];

        $set($attribute, $translations, shouldCallUpdatedHooks: true);

        $livewire = $component->getLivewire();

        if (method_exists($livewire, 'refreshFormData')) {
            /** @var callable(array<string>) $refresh */
            $refresh = [$livewire, 'refreshFormData'];
            $refresh([$attribute]);
        }
    });
}

protected function prepareRepeaterForLocale(
    Repeater $repeater,
    string $attribute,
    string $locale
): void {
    $repeater->afterStateHydrated(function (Repeater $component) use ($attribute, $locale): void {
        $get = $component->makeGetUtility();
        $set = $component->makeSetUtility();

        $attributeState = $get($attribute);

        // Нормализуем состояние если нужно
        if (!is_array($attributeState)) {
            $attributeState = $this->normaliseAttributeState($component, $attribute, $attributeState);
            $set($attribute, $attributeState, shouldCallUpdatedHooks: false);
        }

        // Получаем данные для текущей локали
        $localeData = Arr::get($attributeState, $locale, []);

        // Repeater ожидает массив items [[field1 => val1, ...], ...]
        $component->state($localeData);
    });

    $repeater->afterStateUpdated(function (Repeater $component, $state) use ($attribute, $locale): void {
        $get = $component->makeGetUtility();
        $set = $component->makeSetUtility();

        $translations = $get($attribute);

        if (!is_array($translations)) {
            $translations = [];
        }

        // Сохраняем весь массив items для локали
        $translations[$locale] = $state ?? [];

        $set($attribute, $translations, shouldCallUpdatedHooks: true);

        $livewire = $component->getLivewire();

        if (method_exists($livewire, 'refreshFormData')) {
            /** @var callable(array<string>) $refresh */
            $refresh = [$livewire, 'refreshFormData'];
            $refresh([$attribute]);
        }
    });
}
```

### Почему это работает

1. **Builder.php:110-122** устанавливает свой `afterStateHydrated`, но он срабатывает ПОСЛЕ нашего хука
2. Наш хук устанавливает state всего Builder → Builder's хук конвертирует rawState в items с UUID
3. При изменении полей внутри Builder:
   - Livewire wire:model обновляет данные в `rawState`
   - Builder's actions (add, delete, edit, moveUp, moveDown) вызывают `callAfterStateUpdated()`
   - Наш хук перехватывает это и синхронизирует с JSON-переводами
4. Дочерние поля НЕ НУЖДАЮТСЯ в специальных хуках, т.к. они работают через `statePath("{index}.data.{field}")` который автоматически управляется Builder

### Механизм работы хуков в Filament 4.x

Из исходников `HasState.php:198-213`:

```php
protected function callAfterStateUpdatedHooks(): static
{
    foreach ($this->afterStateUpdated as $callback) {
        $runId = spl_object_id($callback) . md5(json_encode($this->getState()));

        // Предотвращение дублирования вызовов
        if (store($this)->has('executedAfterStateUpdatedCallbacks', iKey: $runId)) {
            continue;
        }

        $this->callAfterStateUpdatedHook($callback);

        store($this)->push('executedAfterStateUpdatedCallbacks', value: $runId, iKey: $runId);
    }

    return $this;
}
```

Ключевые особенности:
- Хуки могут быть добавлены несколько раз через `afterStateUpdated()`
- Filament предотвращает дублирующиеся вызовы через store и runId
- `callAfterStateUpdated($shouldBubbleToParents = true)` может пробрасывать событие в родительские компоненты
- Этот механизм работает для всех компонентов (Field, Builder, Repeater)

### Важные детали

1. **Не изменять statePath дочерних полей** - Builder управляет ими автоматически через `statePath("{itemIndex}.data")`
2. **Не устанавливать хуки на дочерние поля напрямую** - Builder вызывает `callAfterStateUpdated()` который пробрасывается вверх
3. **Доверять механизму Filament 4.x** - Builder правильно синхронизирует дочерние элементы через Livewire wire:model
4. **normaliseAttributeState доступен как protected метод** - можно вызывать из Builder/Repeater хуков
5. **afterStateUpdated может быть вызван несколько раз** - Filament автоматически предотвращает дублирование через store
6. **Методы reactive() и live() удалены** - используйте `afterStateUpdated()` для PHP и `afterStateUpdatedJs()` для JavaScript реактивности

### Ограничения и будущие улучшения

**Текущее решение НЕ поддерживает**:
- Nested Builder внутри Builder blocks
- Nested Repeater внутри Builder blocks
- Nested Builder/Repeater внутри Repeater items

**Для поддержки nested структур** потребуется:
1. Рекурсивная обработка через `getBlocks()` и `getChildSchema()`
2. Определение, является ли дочерний компонент контейнером
3. Применение тех же хуков к nested компонентам

Но для 95% случаев использования базовое решение будет достаточным.

## Заключение

**Рекомендуемое решение**: Двухуровневая обработка с хуками на уровне контейнера (Builder/Repeater).

**Приоритет**: Высокий - это критический баг, блокирующий использование Builder/Repeater с переводами.

**Оценка**:
- Базовое решение (Builder + Repeater): 3-4 часа разработки + 2-3 часа тестирования
- С поддержкой nested структур: +4-6 часов разработки + 2-3 часа тестирования

**Риски**:
- Низкие для базового решения (опирается на механизмы Filament)
- Средние для nested структур (требует тестирования edge cases)

**Преимущества этого подхода**:
- Минимальные изменения в существующем коде
- Использует встроенные механизмы Filament
- Не конфликтует с пользовательскими хуками
- Легко тестируется
- Обратно совместим
