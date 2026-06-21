import { initGallery } from './admin/gallery.js';
import { initSettings } from './admin/settings.js';

document.addEventListener('DOMContentLoaded', () => {
    initGallery();
    initSettings();
});
