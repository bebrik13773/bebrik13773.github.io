import * as THREE from '../three.js';
import { MAX_DPR } from '../config.js';

// Проверка WebGL без создания рендерера (чтобы показать понятное сообщение).
export function detectWebGL() {
    try {
        const canvas = document.createElement('canvas');
        const gl = canvas.getContext('webgl2') || canvas.getContext('webgl');
        if (!gl) return false;
        const lose = gl.getExtension('WEBGL_lose_context');
        if (lose) lose.loseContext();
        return true;
    } catch (e) {
        return false;
    }
}

// Хост рендерера. Сглаживание задаётся только при создании контекста, поэтому при смене
// «antialias» пресета канвас пересоздаётся (остальные настройки меняются на лету).
export function createRendererHost({ container, onContextLost, onContextRestored }) {
    let canvas = null;
    let renderer = null;
    let antialias = null;
    let lastPreset = null;

    function attachCanvasEvents(el) {
        el.addEventListener('webglcontextlost', (event) => {
            event.preventDefault();
            if (onContextLost) onContextLost();
        });
        el.addEventListener('webglcontextrestored', () => {
            if (onContextRestored) onContextRestored();
        });
    }

    function build(preset) {
        if (renderer) {
            renderer.dispose();
            if (canvas && canvas.parentNode) canvas.parentNode.removeChild(canvas);
        }
        canvas = document.createElement('canvas');
        canvas.id = 'wfCanvas';
        container.appendChild(canvas);
        attachCanvasEvents(canvas);
        renderer = new THREE.WebGLRenderer({
            canvas,
            antialias: preset.antialias,
            powerPreference: 'high-performance',
            alpha: false,
            stencil: false,
        });
        renderer.outputColorSpace = THREE.SRGBColorSpace;
        antialias = preset.antialias;
    }

    function resize() {
        if (!renderer) return;
        const scale = lastPreset ? lastPreset.renderScale : 1;
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, MAX_DPR) * scale);
        renderer.setSize(window.innerWidth, window.innerHeight, false);
    }

    return {
        get renderer() { return renderer; },
        get canvas() { return canvas; },
        // Применить пресет. Возвращает true, если рендерер был пересоздан.
        apply(preset) {
            const rebuilt = !renderer || antialias !== preset.antialias;
            lastPreset = preset;
            if (rebuilt) build(preset);
            renderer.shadowMap.enabled = preset.shadows === 'real';
            renderer.shadowMap.type = THREE.PCFShadowMap;
            resize();
            return rebuilt;
        },
        resize,
        info() {
            if (!renderer) return null;
            const { render, memory } = renderer.info;
            return {
                calls: render.calls,
                triangles: render.triangles,
                geometries: memory.geometries,
                textures: memory.textures,
                pixelRatio: renderer.getPixelRatio(),
                width: renderer.domElement.width,
                height: renderer.domElement.height,
            };
        },
        dispose() {
            if (renderer) renderer.dispose();
        },
    };
}
