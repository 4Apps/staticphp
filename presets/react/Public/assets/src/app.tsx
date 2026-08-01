import { createRoot } from 'react-dom/client';
import { App } from './react/App';

const container = document.getElementById('react-root');
if (container) {
    createRoot(container).render(<App />);
}
