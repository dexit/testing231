import { render } from '@wordpress/element';
import App from './App';

const root = document.getElementById('wrm-admin-app-root');
if (root) {
  render(<App />, root);
}
