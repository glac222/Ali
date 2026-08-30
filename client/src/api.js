const BASE = (import.meta.env.VITE_API_URL || '') + '/api';

async function request(path, options = {}) {
  const res = await fetch(BASE + path, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
  });
  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(body.error || `Error ${res.status}`);
  }
  if (res.status === 204) return null;
  return res.json();
}

export const api = {
  pantry: {
    list: () => request('/pantry'),
    create: (data) => request('/pantry', { method: 'POST', body: JSON.stringify(data) }),
    update: (id, data) => request(`/pantry/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
    remove: (id) => request(`/pantry/${id}`, { method: 'DELETE' }),
  },
  recipes: {
    list: () => request('/recipes'),
    get: (slug) => request(`/recipes/${slug}`),
  },
  plan: {
    all: () => request('/plan'),
  },
  shoppingList: {
    get: (period) => request(`/shopping-list?period=${encodeURIComponent(period)}`),
    create: (data) => request('/shopping-list', { method: 'POST', body: JSON.stringify(data) }),
    generate: (period) => request('/shopping-list/generate', { method: 'POST', body: JSON.stringify({ period }) }),
    update: (id, data) => request(`/shopping-list/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
    remove: (id) => request(`/shopping-list/${id}`, { method: 'DELETE' }),
  },
  discoveries: {
    list: () => request('/discoveries'),
    rate: (id, rating) => request(`/discoveries/${id}/rating`, { method: 'PUT', body: JSON.stringify({ rating }) }),
  },
  nutrition: {
    today: () => request('/nutrition/today'),
  },
  meals: {
    list: (date) => request('/meals' + (date ? `?date=${encodeURIComponent(date)}` : '')),
    today: () => request('/meals/today'),
    remove: (id) => request(`/meals/${id}`, { method: 'DELETE' }),
  },
  prefs: {
    all: () => request('/prefs'),
    set: (key, value) => request('/prefs', { method: 'PUT', body: JSON.stringify({ key, value }) }),
    remove: (key) => request(`/prefs/${encodeURIComponent(key)}`, { method: 'DELETE' }),
  },
  chat: {
    status: () => request('/chat/status'),
    history: () => request('/chat/history'),
    events: () => request('/chat/events'),
    // devuelve { reply, simulated, actions:[{tool,resumen}], changed:[dominios] }
    send: (message, history) => request('/chat', { method: 'POST', body: JSON.stringify({ message, history }) }),
  },
};
