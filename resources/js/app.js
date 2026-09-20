import Alpine from 'alpinejs';
import './interactions';
import { startLeaderPortal } from './leader-offline';

window.Alpine = Alpine;

Alpine.start();

// The Tour Leader Portal's offline queue. It returns immediately on every
// page that is not one of its own, so this costs nothing elsewhere.
startLeaderPortal();
