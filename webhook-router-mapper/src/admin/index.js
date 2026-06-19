import { render } from '@wordpress/element';
import App from './App';

const root = document.getElementById('wrm-admin-app-root');
if (root) {
  try {
    render(<App />, root);
  } catch (e) {
    root.innerHTML =
      '<div style="color:#d63638;padding:20px;border:2px solid #d63638;border-radius:6px;margin:16px;background:#fde8e8">' +
      '<strong>Failed to initialise admin app.</strong> Check the browser console for details.</div>';
  }
}
