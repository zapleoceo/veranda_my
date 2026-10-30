(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.MenuSearch = api;
})(typeof window === 'undefined' ? globalThis : window, function () {
  const normalize = (value) => String(value || '').toLowerCase().normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/ё/g, 'е');

  function matches(text, query) {
    const haystack = normalize(text);
    return normalize(query).trim().split(/\s+/).every((word) => haystack.includes(word));
  }

  function init(content, initialQuery = '') {
    const input = content.querySelector('[data-menu-search]');
    if (!input) return null;
    const clear = content.querySelector('[data-menu-search-clear]');
    const status = content.querySelector('[data-menu-search-status]');
    const sections = Array.from(content.querySelectorAll('.section')).map((section) => ({
      node: section,
      categories: Array.from(section.querySelectorAll('details[data-key]')).map((category) => ({
        node: category,
        count: category.querySelector('.sum-right'),
        label: category.querySelector('summary span').textContent,
        items: Array.from(category.querySelectorAll('.item')).map((item) => ({
          node: item,
          text: [item.querySelector('.item-title'), item.querySelector('.item-desc')]
            .map((node) => node ? node.textContent : '').join(' '),
        })),
      })),
    }));
    let savedOpen = null;
    function update() {
      const active = input.value.trim().length > 0;
      if (active && !savedOpen) savedOpen = new Map(sections.flatMap((section) =>
        section.categories.map((category) => [category.node, category.node.open])));
      let total = 0;
      sections.forEach((section) => {
        let sectionCount = 0;
        section.categories.forEach((category) => {
          let count = 0;
          category.items.forEach((item) => {
            const visible = matches(category.label + ' ' + item.text, input.value);
            item.node.hidden = !visible;
            if (visible) count++;
          });
          category.node.hidden = active && count === 0;
          if (active) category.node.open = count > 0;
          else if (savedOpen) category.node.open = savedOpen.get(category.node);
          category.count.textContent = String(count);
          sectionCount += count;
        });
        section.node.hidden = active && sectionCount === 0;
        total += sectionCount;
      });
      if (!active) savedOpen = null;
      clear.hidden = !input.value;
      status.hidden = !active;
      status.textContent = active ? (total ? status.dataset.results.replace('{count}', String(total)) : status.dataset.empty) : '';
    }
    function reset() { input.value = ''; update(); input.focus(); }
    input.value = initialQuery;
    input.addEventListener('input', update);
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') { event.preventDefault(); reset(); }
      if (event.key === 'Enter') event.preventDefault();
    });
    clear.addEventListener('click', reset);
    update();
    return {
      query: () => input.value,
      openKeys: () => sections.flatMap((section) => section.categories
        .filter((category) => savedOpen ? savedOpen.get(category.node) : category.node.open)
        .map((category) => category.node.getAttribute('data-key'))),
      update,
    };
  }
  return { normalize, matches, init };
});
