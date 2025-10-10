<div
    x-data="{}"
    x-on:language-tab-changed="
    console.log('hi');
        const locale = $event.detail.currentLocale;
        console.log('[LanguageTabs] Tab changed to:', locale);

        $nextTick(() => {
            // Находим Tabs компонент внутри этого LanguageTabs
            const tabsEl = $el.querySelector('[x-data*=\'tabsSchemaComponent\']');
            if (!tabsEl) {
                console.log('[LanguageTabs] Tabs component not found');
                return;
            }

            // Находим активный tabpanel для нашей локали
            const activeTab = tabsEl.querySelector('[role=\'tabpanel\'][id*=\'tab_' + locale + '\']:not([style*=\'display: none\'])');
            if (!activeTab) {
                console.log('[LanguageTabs] Active tab not found for locale:', locale);
                return;
            }

            console.log('[LanguageTabs] Found active tab for locale:', locale);

            // Ищем EditorJS только внутри активного таба
            const editors = activeTab.querySelectorAll('[x-data*=\'editorjs\']');
            console.log('[LanguageTabs] EditorJS found:', editors.length);

            editors.forEach(editorEl => {
                const alpineData = Alpine.$data(editorEl);
                if (!alpineData?.instance) return;

                console.log('[LanguageTabs] StatePath:', alpineData.statePath);

                // Получаем Livewire компонент
                const livewireEl = editorEl.closest('[wire\\\\:id]');
                if (livewireEl?.__livewire) {
                    const freshData = livewireEl.__livewire.get(alpineData.statePath);
                    console.log('[LanguageTabs] Fresh data:', freshData);

                    if (freshData) {
                        alpineData.state = freshData;
                    }
                }

                // Рендерим EditorJS
                const state = alpineData.state || { blocks: [] };
                alpineData.instance.render(state).catch(console.error);
            });
        });
    "
>
    {{$getChildComponentContainer()}}
</div>



