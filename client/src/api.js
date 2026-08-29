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
    update: (id, data) => request(`/shopping-list/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  },
  discoveries: {
    list: () => request('/discoveries'),
    rate: (id, rating) => request(`/discoveries/${id}/rating`, { method: 'PUT', body: JSON.stringify({ rating }) }),
  },
  nutrition: {
    today: () => request('/nutrition/today'),
  },
  chat: {
    status: () => request('/chat/status'),
    send: (message, history) => request('/chat', { method: 'POST', body: JSON.stringify({ message, history }) }),
  },
};
