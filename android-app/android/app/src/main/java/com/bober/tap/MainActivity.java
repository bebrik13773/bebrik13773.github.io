package com.bober.tap;

import android.os.Bundle;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.widget.Toast;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    // Сколько миллисекунд ждём повторного нажатия "назад", чтобы выйти
    private static final long BACK_PRESS_EXIT_WINDOW_MS = 2000;
    private long lastBackPressTime = 0;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // super.onCreate() у BridgeActivity синхронно создаёт WebView и уже
        // запускает загрузку appUrl — настройки WebSettings по умолчанию
        // (LOAD_DEFAULT, т.е. с кэшем) применяются ДО того, как мы сюда
        // возвращаемся. Поэтому первую загрузку не перехватить отсюда —
        // но именно она не проблема: кэш на холодном старте и так пуст.
        // Наша задача — гарантировать отсутствие кэша на ВСЕХ следующих
        // загрузках (возврат из фона, ручной reload, переход по ссылке).
        super.onCreate(savedInstanceState);
        disableWebViewCaching();
    }

    @Override
    public void onResume() {
        super.onResume();
        // При каждом возврате приложения на передний план принудительно
        // перечитываем страницу с сервера, игнорируя любой локальный кэш.
        WebView webView = getBridge() != null ? getBridge().getWebView() : null;
        if (webView != null) {
            webView.getSettings().setCacheMode(WebSettings.LOAD_NO_CACHE);
        }
    }

    /**
     * Полностью отключаем кэширование страницы в WebView: ни диска, ни
     * HTTP-кэша браузера. Игра должна всегда грузить свежую версию с
     * сервера, а не показывать устаревший HTML/CSS/JS.
     */
    private void disableWebViewCaching() {
        WebView webView = getBridge().getWebView();
        if (webView == null) {
            return;
        }
        WebSettings settings = webView.getSettings();
        settings.setCacheMode(WebSettings.LOAD_NO_CACHE);
        settings.setDomStorageEnabled(true); // localStorage игре нужен, это не HTTP-кэш
        webView.clearCache(true);
        webView.clearHistory();
    }

    @Override
    public void onBackPressed() {
        // Если можем перейти на предыдущую страницу внутри самого WebView
        // (была внутренняя навигация) — обычное поведение "назад".
        WebView webView = getBridge() != null ? getBridge().getWebView() : null;
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
            return;
        }

        // На "корневой" странице — требуем два нажатия подряд для выхода,
        // чтобы случайное нажатие не закрывало игру.
        long now = System.currentTimeMillis();
        if (now - lastBackPressTime < BACK_PRESS_EXIT_WINDOW_MS) {
            super.onBackPressed(); // реально закрываем приложение
        } else {
            lastBackPressTime = now;
            Toast.makeText(this, "Нажмите ещё раз, чтобы выйти", Toast.LENGTH_SHORT).show();
        }
    }
}
