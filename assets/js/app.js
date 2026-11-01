document.addEventListener('DOMContentLoaded', () => {
  const search = document.getElementById('eventSearch');
  const filter = document.getElementById('eventFilter');
  const cards = [...document.querySelectorAll('.event-card')];

  function updateEvents() {
    const query = (search?.value || '').trim().toLowerCase();
    const mode = filter?.value || 'all';
    cards.forEach(card => {
      const matchesText = (card.dataset.title || '').includes(query);
      const price = Number(card.dataset.price || 0);
      const matchesPrice = mode === 'all' || (mode === 'free' && price === 0) || (mode === 'paid' && price > 0);
      card.hidden = !(matchesText && matchesPrice);
    });
  }
  search?.addEventListener('input', updateEvents);
  filter?.addEventListener('change', updateEvents);
});
