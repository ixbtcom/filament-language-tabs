# Проблема с EditorJS в LanguageTabs

> **Дата**: 2025-10-09
> **Статус**: Диагностирована
> **Компонент**: `/Users/mikhailpanyushkin/code/xcom/filament-editorjs`

---

## Описание проблемы

При переключении табов с локалями EditorJS показывает **пустой редактор** вместо содержимого другой локали.

**Симптомы:**
- Открываешь `ru` → видишь контент ✅
- Переключаешься на `en` → видишь **пустой редактор** ❌
- Если `en` первый в конфиге → рендерится нормально ✅
- Пустые локали показывают пустой редактор вместо "Добавить блок" ❌

**Структура:**
```php
LanguageTabs::make([
    Builder::make('blocks')
        ->blocks([
            Block::make('editorjs')->schema([
                EditorJs::make('content')  // ← Проблема здесь
            ])
        ])
])
```

---

## Корневая причина

### 1. `wire:ignore` блокирует обновления Livewire

**Файл**: `resources/views/forms/components/fields/editorjs.blade.php:15`

```blade
<div
    wire:ignore  ← ❌ Livewire НЕ обновляет этот DOM
    x-data="editorjs({
        state: $wire.$entangle('{$statePath}'),
        ...
    })"
></div>
```

**Проблема**: `wire:ignore` говорит Livewire "не трогай этот элемент". Это нужно для EditorJS, но создает проблему:

1. Livewire обновляет `$wire` state при переключении табов
2. Alpine получает новый state через `$entangle`
3. **НО** EditorJS instance НЕ узнает об изменении state
4. EditorJS продолжает показывать старые данные

---

### 2. EditorJS создается один раз в `init()`

**Файл**: `resources/js/editorjs.js:51-491`

```js
init() {
    // EditorJS создается ОДИН РАЗ при инициализации
    this.instance = new EditorJS({
        holder: this.$el,
        data: this.state,  // ← Начальные данные
        onChange: () => {
            // Обновляет Alpine state при изменении редактора
            this.instance.save().then((outputData) => {
                this.state = outputData;
            });
        }
    });
}
```

**Проблема**:
- EditorJS инициализируется с `this.state` один раз
- Когда Alpine state меняется (через `$entangle`), EditorJS **не обновляется**
- Нет watcher на изменение `state`

---

### 3. Поток данных при переключении табов

```
[User кликает Tab "EN"]
    ↓
[Livewire обновляет $wire.blocks]
    ↓
[Alpine $entangle получает новый state]
    ↓
[Alpine this.state = новые данные EN]
    ↓
[EditorJS instance НЕ РЕАГИРУЕТ] ❌
    ↓
[EditorJS показывает старые данные RU или пусто]
```

**Ожидаемый поток:**

```
[Alpine this.state = новые данные EN]
    ↓
[$watch срабатывает]
    ↓
[EditorJS.render(новые данные)] или [destroy → recreate]
    ↓
[EditorJS показывает данные EN] ✅
```

---

## Почему "EditorJS только один на странице"

EditorJS **не ограничен одним инстансом**, но имеет проблемы:

1. **Привязка к DOM**: Каждый instance привязан к конкретному `holder` (DOM элемент)
2. **Скрытые элементы**: EditorJS плохо работает с `display: none` или `x-show="false"`
3. **Livewire wire:ignore**: При множественных EditorJS с `wire:ignore` возникают конфликты

**В нашем случае:**
```
Tab RU (active)
  Builder blocks_ru
    EditorJS content_ru  ← Виден, работает

Tab EN (hidden)
  Builder blocks_en
    EditorJS content_en  ← Скрыт через CSS, instance создан но не обновляется
```

---

## Решения

### Решение 1: Добавить `$watch` на state (РЕКОМЕНДУЕМОЕ)

Обновлять EditorJS когда Alpine state меняется.

**Изменить**: `resources/js/editorjs.js`

```js
init() {
    const toolsOptions = this.toolsOptions ?? {};
    // ... остальная логика ...

    // Создаем EditorJS
    this.instance = new EditorJS({
        holder: this.$el,
        minHeight,
        data: this.state,
        placeholder,
        readOnly,
        tools: enabledTools,
        // ... i18n и onChange ...
    });

    // ✅ ДОБАВИТЬ: Watcher на изменение state
    this.$watch('state', (newState, oldState) => {
        // Избегаем циклических обновлений
        if (JSON.stringify(newState) === JSON.stringify(oldState)) {
            return;
        }

        // Если EditorJS готов - обновляем данные
        if (this.instance && this.instance.isReady) {
            this.instance.render(newState).catch((error) => {
                console.error('EditorJS render error:', error);
            });
        }
    });
},
```

**Плюсы:**
- ✅ Минимальные изменения
- ✅ Сохраняет существующий instance
- ✅ Быстро работает

**Минусы:**
- ⚠️ `render()` может не полностью очищать старое состояние
- ⚠️ Возможны конфликты если пользователь редактирует во время переключения

---

### Решение 2: Destroy → Recreate при изменении state

Полностью пересоздавать EditorJS при смене локали.

```js
init() {
    // Функция создания EditorJS
    const createEditor = (initialData) => {
        // Уничтожаем старый instance если есть
        if (this.instance) {
            this.instance.destroy();
            this.instance = null;
        }

        // Создаем новый
        this.instance = new EditorJS({
            holder: this.$el,
            data: initialData,
            // ... остальное ...
        });
    };

    // Создаем начальный editor
    createEditor(this.state);

    // Watcher для пересоздания
    this.$watch('state', (newState) => {
        createEditor(newState);
    });
},
```

**Плюсы:**
- ✅ Чистое состояние при каждом переключении
- ✅ Нет проблем с остаточными данными

**Минусы:**
- ⚠️ Медленнее (создание занимает ~100-300ms)
- ⚠️ Видимое "мерцание" при переключении
- ⚠️ Теряется undo history

---

### Решение 3: Отдельный EditorJS для каждой локали (НЕ РЕКОМЕНДУЕТСЯ)

Создавать отдельные EditorJS instances для каждого таба.

**Проблемы:**
- ❌ Большой memory overhead (каждый EditorJS ~5-10MB)
- ❌ Медленная инициализация формы
- ❌ Конфликты между скрытыми instances
- ❌ Проблемы с `wire:ignore` и множественными instances

---

### Решение 4: Добавить `wire:key` для форсирования пересоздания (АЛЬТЕРНАТИВА)

Убрать `wire:ignore` и использовать `wire:key` для пересоздания компонента.

**Изменить**: `resources/views/forms/components/fields/editorjs.blade.php`

```blade
<div
    {{-- wire:ignore ← УБРАТЬ --}}
    wire:key="editorjs-{{ $statePath }}-{{ $currentLocale }}"  ← ДОБАВИТЬ
    x-load
    x-data="editorjs({...})"
></div>
```

**Проблемы:**
- ❌ Livewire будет полностью пересоздавать DOM при каждом изменении
- ❌ EditorJS будет инициализироваться заново → медленно
- ❌ Теряется состояние редактора при любом изменении формы

---

## Рекомендуемое решение

### ✅ Решение 1: Добавить `$watch` на state

**Почему:**
- Минимальные изменения в коде
- Быстро работает
- Не ломает существующую логику
- Совместимо с `wire:ignore`

**Реализация:**

1. Открыть `/Users/mikhailpanyushkin/code/xcom/filament-editorjs/resources/js/editorjs.js`
2. Найти метод `init()` (строка 51)
3. После создания EditorJS instance (строка 490, после `onReady`) добавить:

```js
onReady: () => {
    const editor = this.instance;
    new Undo({ editor });
    new DragDrop(editor);
},
```

**После этого блока добавить:**

```js
});

// ✅ НОВЫЙ КОД: Watcher для обновления EditorJS при изменении state
this.$watch('state', async (newState, oldState) => {
    // Пропускаем если state не изменился (избегаем циклов)
    if (JSON.stringify(newState) === JSON.stringify(oldState)) {
        this.log('State unchanged, skipping EditorJS update');
        return;
    }

    this.log('State changed, updating EditorJS', { newState, oldState });

    // Ждем пока EditorJS будет готов
    if (!this.instance || !this.instance.isReady) {
        this.log('EditorJS not ready yet');
        return;
    }

    try {
        // Очищаем и рендерим новые данные
        await this.instance.render(newState || { blocks: [] });
        this.log('EditorJS updated successfully');
    } catch (error) {
        console.error('Failed to update EditorJS:', error);

        // Fallback: пересоздаем editor если render() не сработал
        this.log('Attempting to recreate EditorJS');
        try {
            await this.instance.destroy();
            // Пересоздаем через небольшую задержку
            setTimeout(() => {
                this.init();
            }, 100);
        } catch (destroyError) {
            console.error('Failed to destroy/recreate EditorJS:', destroyError);
        }
    }
});
```

4. Пересобрать JavaScript:
```bash
cd /Users/mikhailpanyushkin/code/xcom/filament-editorjs
npm run build
# или npm run dev для разработки
```

---

## Тестирование

После применения решения проверить:

### 1. Переключение между существующими локалями

- [ ] Открыть запись с данными в RU и EN
- [ ] Переключиться RU → EN
- [ ] Содержимое должно обновиться ✅
- [ ] Переключиться EN → RU
- [ ] Содержимое должно вернуться ✅

### 2. Пустые локали

- [ ] Переключиться на пустую локаль (например, FR)
- [ ] Должен показаться пустой редактор с placeholder ✅
- [ ] Добавить блок
- [ ] Переключиться на другую локаль и вернуться
- [ ] Данные должны сохраниться ✅

### 3. Редактирование во время работы

- [ ] Начать редактировать текст в RU
- [ ] Переключиться на EN (не сохраняя)
- [ ] Вернуться на RU
- [ ] **Не сохраненные изменения потеряются** (ожидаемое поведение)

### 4. Множественные EditorJS на странице

- [ ] Создать форму с несколькими Builder blocks
- [ ] Каждый должен работать независимо ✅

### 5. Console errors

- [ ] Открыть DevTools → Console
- [ ] Не должно быть ошибок EditorJS ✅
- [ ] Debug logs должны показываться (если `debugEnabled: true`)

---

## Альтернатива: Исправление в LanguageTabs

Если изменение EditorJS невозможно, можно попробовать исправить в LanguageTabs:

### Добавить JavaScript хук для обновления EditorJS

**В**: `src/Forms/Components/LanguageTabs.php`

Добавить `afterStateUpdatedJs`:

```php
protected function prepareBuilderForLocale(...)
{
    // ... существующий код ...

    $builder->afterStateUpdatedJs(<<<JS
        // Найти все EditorJS внутри Builder
        \$el.querySelectorAll('[x-data*="editorjs"]').forEach(editorEl => {
            const alpineComponent = Alpine.\$data(editorEl);
            if (alpineComponent && alpineComponent.instance) {
                // Форсируем обновление EditorJS
                const newState = alpineComponent.state;
                alpineComponent.instance.render(newState || { blocks: [] });
            }
        });
    JS);
}
```

**Проблемы:**
- ⚠️ Сложно найти правильные EditorJS instances
- ⚠️ Может срабатывать слишком часто
- ⚠️ Зависит от внутренней структуры Alpine

---

## Заключение

**Проблема**: EditorJS не обновляется при изменении Alpine state через `$entangle`.

**Корневая причина**: Отсутствие watcher на `state` в Alpine компоненте.

**Рекомендуемое решение**: Добавить `$watch('state', ...)` в `init()` метод EditorJS.

**Время реализации**: ~30 минут (изменение + тестирование)

**Риск**: Низкий - изменения локальные, не влияют на остальную функциональность.

---

## Дополнительные улучшения (опционально)

### 1. Debounce для watcher

Избежать частых обновлений:

```js
let debounceTimer = null;
this.$watch('state', (newState) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        this.instance.render(newState);
    }, 150);
});
```

### 2. Сохранение undo history

Сохранять историю изменений между переключениями:

```js
const undoHistory = {};
this.$watch('state', (newState) => {
    const locale = this.statePath.split('.').pop();
    undoHistory[locale] = this.instance.history.getHistory();
    // ... render ...
    if (undoHistory[locale]) {
        this.instance.history.setHistory(undoHistory[locale]);
    }
});
```

### 3. Плавная анимация переключения

Добавить fade-in/out при смене локалей:

```js
this.$el.style.opacity = '0';
await this.instance.render(newState);
this.$el.style.transition = 'opacity 0.2s';
this.$el.style.opacity = '1';
```
