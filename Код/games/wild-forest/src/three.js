// Единая точка подключения Three.js: фиксированная версия лежит в vendor/ (см. vendor/VERSION).
// Обычный относительный импорт вместо importmap: работает и в старых Android WebView.
export * from '../vendor/three.module.min.js';
