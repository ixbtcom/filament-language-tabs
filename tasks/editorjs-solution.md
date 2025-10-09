# Решение: Вызывать callAfterStateUpdated() для Builder

## Проблема

EditorJS не обновляется при переключении табов, но обновляется при добавлении блока через UI Builder.

## Что делает Builder при добавлении блока

**Файл**: `/Users/mikhailpanyushkin/code/xcom/libs/forms/src/Components/Builder.php:176-182`

```php
// Метод getAddAction()
->action(function (array $arguments, Builder $component, array $data = []): void {
    // ...
    $component->rawState($items);  // Обновляет state
    $component->getChildSchema($newUuid)->fill($data);  // Заполняет schema
    $component->callAfterStateUpdated();  // ← КЛЮЧЕВОЙ ВЫЗОВ!
    // ...
})
```

**Все действия Builder вызывают `callAfterStateUpdated()`:**
- Add (строка 182)
- AddBetween (строка 275)
- Clone (строка 337)
- Delete (строка 378)
- MoveDown (строка 418)
- MoveUp (строка 458)
- Reorder (строка 508)
- Edit (строка 187)

Это триггерит:
1. Вызов всех хуков `afterStateUpdated`
2. Пробрасывание события вверх к родительским компонентам
3. Обновление Livewire state
4. **Обновление EditorJS через watcher!**

## Решение

Добавить вызов `callAfterStateUpdated()` после установки state в LanguageTabs.

### Изменить в LanguageTabs.php

**Файл**: `/Users/mikhailpanyushkin/code/xcom/filament-language-tabs/src/Forms/Components/LanguageTabs.php`

**Найти строки 293-298 (prepareBuilderForLocale):**

```php
$localeData = Arr::get($attributeState, $locale);

// ВСЕГДА устанавливаем state для сброса предыдущей локали
// Если данных нет (null) - передаем [] для дефолтного UI Builder
$component->state(is_array($localeData) ? $localeData : []);
```

**Заменить на:**

```php
$localeData = Arr::get($attributeState, $locale);

// ВСЕГДА устанавливаем state для сброса предыдущей локали
// Если данных нет (null) - передаем [] для дефолтного UI Builder
$component->state(is_array($localeData) ? $localeData : []);

// ✅ КЛЮЧЕВОЕ ИСПРАВЛЕНИЕ: Вызываем callAfterStateUpdated()
// Это триггерит обновление вложенных компонентов (EditorJS)
$component->callAfterStateUpdated();
```

### Аналогично для Repeater

**Найти строки 359-364 (prepareRepeaterForLocale):**

```php
$localeData = Arr::get($attributeState, $locale);

// ВСЕГДА устанавливаем state для сброса предыдущей локали
// Если данных нет (null) - передаем [] для дефолтного UI Builder
$component->state(is_array($localeData) ? $localeData : []);
```

**Заменить на:**

```php
$localeData = Arr::get($attributeState, $locale);

// ВСЕГДА устанавливаем state для сброса предыдущей локали
// Если данных нет (null) - передаем [] для дефолтного UI Builder
$component->state(is_array($localeData) ? $localeData : []);

// ✅ КЛЮЧЕВОЕ ИСПРАВЛЕНИЕ: Вызываем callAfterStateUpdated()
// Это триггерит обновление вложенных компонентов
$component->callAfterStateUpdated();
```

## Почему это работает

### Поток с `callAfterStateUpdated()`:

```
[Переключение Tab RU → EN]
  ↓
[LanguageTabs.prepareBuilderForLocale вызывает afterStateHydrated]
  ↓
[Нативный хук Builder создает items с UUID]
  ↓
[Логика LanguageTabs обновляет state]
  ↓
[component.state([...en data...])]
  ↓
[component.callAfterStateUpdated()] ← НОВЫЙ ВЫЗОВ!
  ↓
[Все хуки afterStateUpdated срабатывают]
  ↓
[Livewire обновляет $wire state]
  ↓
[Alpine $entangle получает новый state]
  ↓
[EditorJS watcher срабатывает]
  ↓
[EditorJS.render(новые данные)] ✅
  ↓
[Редактор показывает контент EN]
```

### Без `callAfterStateUpdated()` (текущее):

```
[component.state([...en data...])]
  ↓
[Хуки afterStateUpdated НЕ ВЫЗЫВАЮТСЯ] ❌
  ↓
[Livewire state НЕ ОБНОВЛЯЕТСЯ]
  ↓
[EditorJS не получает событие]
  ↓
[Показывается пустой редактор]
```

## Полный код изменений

```php
// В prepareBuilderForLocale (строка ~293)
$localeData = Arr::get($attributeState, $locale);
$component->state(is_array($localeData) ? $localeData : []);
$component->callAfterStateUpdated();  // ← ДОБАВИТЬ

// В prepareRepeaterForLocale (строка ~359)
$localeData = Arr::get($attributeState, $locale);
$component->state(is_array($localeData) ? $localeData : []);
$component->callAfterStateUpdated();  // ← ДОБАВИТЬ
```

## Тестирование

После изменений:

1. Перезагрузи страницу с формой
2. Открой запись с данными в RU и EN
3. Переключись RU → EN → должен показаться контент EN ✅
4. Переключись EN → RU → должен вернуться контент RU ✅
5. Переключись на пустую локаль → должен показаться пустой Builder ✅
6. В console не должно быть ошибок ✅

## Преимущества этого решения

1. ✅ **Минимальные изменения** - всего 2 строки кода
2. ✅ **Использует нативные механизмы** Filament
3. ✅ **Не требует изменений** в EditorJS
4. ✅ **Работает с любыми вложенными** компонентами, не только EditorJS
5. ✅ **Совместимо** с существующими хуками
6. ✅ **Быстро** - нет лишних пересозданий компонентов

## Важные детали

### Не дублирует вызовы

`callAfterStateUpdated()` в Filament имеет встроенную защиту от дублирования:

```php
// HasState.php:198-213
protected function callAfterStateUpdatedHooks(): static
{
    foreach ($this->afterStateUpdated as $callback) {
        $runId = spl_object_id($callback) . md5(json_encode($this->getState()));

        // Проверка на дублирование
        if (store($this)->has('executedAfterStateUpdatedCallbacks', iKey: $runId)) {
            continue;  // Пропускаем уже выполненные
        }

        $this->callAfterStateUpdatedHook($callback);
        store($this)->push('executedAfterStateUpdatedCallbacks', value: $runId);
    }

    return $this;
}
```

### Пробрасывает события вверх

`callAfterStateUpdated()` также вызывается с параметром `$shouldBubbleToParents = true` по умолчанию, что позволяет родительским компонентам (LanguageTabs) получить уведомление.

### Работает с partiallyRender()

Builder опционально вызывает `partiallyRender()` после `callAfterStateUpdated()`, но это нужно только для оптимизации ре-рендера. В нашем случае Livewire сам обновит DOM через Alpine.

## Альтернатива: Если не сработает

Если по какой-то причине `callAfterStateUpdated()` не триггерит обновление EditorJS, можно добавить явный dispatch события:

```php
$component->state(is_array($localeData) ? $localeData : []);
$component->callAfterStateUpdated();

// Дополнительно: явный dispatch Livewire события
$component->getLivewire()->dispatch(
    'builder-updated',
    statePath: $component->getStatePath()
);
```

Но скорее всего это НЕ ПОНАДОБИТСЯ - `callAfterStateUpdated()` должен работать.
