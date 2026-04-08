(() => {
                    const root = document.currentScript && document.currentScript.parentElement ? document.currentScript.parentElement : document;
                    const allBtn = document.querySelector('[data-select-all]');
                    const noneBtn = document.querySelector('[data-select-none]');
                    const boxes = () => Array.from(document.querySelectorAll('input[type="checkbox"][name="allowed_nums[]"]'));
                    if (allBtn) allBtn.addEventListener('click', () => boxes().forEach((cb) => { cb.checked = true; }));
                    if (noneBtn) noneBtn.addEventListener('click', () => boxes().forEach((cb) => { cb.checked = false; }));
                })();
