@guest
<aside class="qf-login-aside" aria-hidden="true">
    <div class="qf-login-aside-inner">
        <div class="qf-login-marca">{{ config('app.name') }}</div>
        <h2>Facturación electrónica<br>simple y sin errores</h2>
        <ul>
            <li>Facturas, boletas y notas en segundos</li>
            <li>Envío automático a SUNAT con reintentos</li>
            <li>Alertas antes de que venza un plazo</li>
        </ul>
        {{-- Maqueta ilustrativa del panel (CSS puro, sin imágenes externas) --}}
        <div class="qf-mock">
            <div class="qf-mock-side"><i></i><i></i><i></i><i></i><i></i></div>
            <div class="qf-mock-main">
                <div class="qf-mock-bar"></div>
                <div class="qf-mock-cards"><b></b><b></b><b></b><b></b></div>
                <div class="qf-mock-chart">
                    <svg viewBox="0 0 200 60" preserveAspectRatio="none"><path d="M0 55 C30 55 35 10 55 10 S80 55 100 55 S130 5 150 8 S180 55 200 55" /></svg>
                </div>
            </div>
        </div>
    </div>
</aside>
@endguest
