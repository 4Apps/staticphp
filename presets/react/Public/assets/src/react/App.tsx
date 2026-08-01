import { useEffect, useState } from 'react';
import { apiBase, get, post } from './api';

interface Item {
    id: number;
    title: string;
    created_at: string;
}

interface ItemsResponse {
    items: Item[];
    served_by: string;
    served_at: string;
}

export function App() {
    const [items, setItems] = useState<Item[]>([]);
    const [servedBy, setServedBy] = useState('');
    const [title, setTitle] = useState('');
    const [error, setError] = useState('');

    useEffect(() => {
        get<ItemsResponse>(apiBase)
            .then((data) => {
                setItems(data.items);
                setServedBy(`${data.served_by} at ${data.served_at}`);
            })
            .catch((e) => setError(e.message));
    }, []);

    async function addItem(event: React.FormEvent) {
        event.preventDefault();
        setError('');

        try {
            const created = await post<{ item: Item }>(`${apiBase}/create`, { title });
            setItems((current) => [...current, created.item]);
            setTitle('');
        } catch (e) {
            setError((e as { message: string }).message);
        }
    }

    return (
        <div className="py-5">
            <h1>React on StaticPHP</h1>
            <p className="text-muted" data-testid="served-by">
                {servedBy ? `Served by ${servedBy}` : 'Loading…'}
            </p>

            <ul data-testid="items">
                {items.map((item) => (
                    <li key={item.id}>
                        {item.title} <small className="text-muted">({item.created_at})</small>
                    </li>
                ))}
            </ul>

            <form onSubmit={addItem} className="mt-3">
                <input
                    type="text"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    placeholder="New item"
                    data-testid="title-input"
                />
                <button type="submit" data-testid="add-button">
                    Add
                </button>
            </form>

            {error && (
                <p className="text-danger mt-2" data-testid="error">
                    {error}
                </p>
            )}
        </div>
    );
}
