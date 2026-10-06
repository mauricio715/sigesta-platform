"""Cliente HTTP asíncrono de la API de SI-GESTA (Laravel).

Usa el token de servicio de SOLO LECTURA (php artisan sigesta:token-ia):
el asistente puede consultar, pero Laravel rechaza cualquier escritura.
"""

from __future__ import annotations

from typing import Any

import httpx


class LaravelApiError(Exception):
    """Error devuelto por la API de Laravel (o falla de conexión, status 503)."""

    def __init__(self, status_code: int, mensaje: str):
        super().__init__(mensaje)
        self.status_code = status_code
        self.mensaje = mensaje


class LaravelClient:
    def __init__(
        self,
        base_url: str,
        token: str,
        timeout: float = 15.0,
        transport: httpx.AsyncBaseTransport | None = None,
    ):
        self._token = token
        self._client = httpx.AsyncClient(
            base_url=base_url.rstrip("/") + "/",
            timeout=timeout,
            transport=transport,
            headers={"Accept": "application/json"},
        )

    async def cerrar(self) -> None:
        await self._client.aclose()

    # ------------------------------------------------------------------
    #  Consultas usadas como herramientas del asistente
    # ------------------------------------------------------------------

    async def get_stock_resumen(self, tipo: str = "TODOS", solo_bajo_stock: bool = False) -> dict[str, Any]:
        return await self._get("inventario/resumen", {"tipo": tipo, "solo_bajo_stock": solo_bajo_stock})

    async def get_alertas_activas(
        self,
        nivel_prioridad: str | None = None,
        tipo_alerta: str | None = None,
    ) -> dict[str, Any]:
        return await self._get(
            "alertas",
            {
                "estado": "pendientes",
                "nivel_prioridad": nivel_prioridad,
                "tipo_alerta": tipo_alerta,
                "per_page": 50,
            },
        )

    async def get_kardex_movimientos(self, params: dict[str, Any] | None = None) -> dict[str, Any]:
        params = {"per_page": 50, **(params or {})}
        return await self._get("movimientos", params)

    async def get_despachos(self, params: dict[str, Any] | None = None) -> dict[str, Any]:
        return await self._get("despachos", {"per_page": 30, **(params or {})})

    async def get_trazabilidad_insumo(self, lote_id: int) -> dict[str, Any]:
        return await self._get(f"trazabilidad/insumo/{int(lote_id)}")

    async def get_trazabilidad_producto(self, lote_id: int) -> dict[str, Any]:
        return await self._get(f"trazabilidad/producto/{int(lote_id)}")

    async def buscar_lotes(
        self,
        codigo: str | None = None,
        tipo_item: str | None = None,
        estado: str | None = None,
    ) -> dict[str, Any]:
        return await self._get(
            "lotes",
            {"buscar": codigo, "tipo_item": tipo_item, "estado": estado, "per_page": 20},
        )

    # ------------------------------------------------------------------
    #  Verificación del usuario que chatea (con SU token, no el de servicio)
    # ------------------------------------------------------------------

    async def verificar_usuario(self, token_usuario: str) -> dict[str, Any]:
        respuesta = await self._get("auth/me", token=token_usuario)
        return respuesta.get("data", {})

    # ------------------------------------------------------------------

    async def _get(
        self,
        ruta: str,
        params: dict[str, Any] | None = None,
        token: str | None = None,
    ) -> dict[str, Any]:
        try:
            respuesta = await self._client.get(
                ruta,
                params=self._limpiar(params),
                headers={"Authorization": f"Bearer {token or self._token}"},
            )
        except httpx.HTTPError as error:
            raise LaravelApiError(503, f"No se pudo conectar con la API de SI-GESTA: {error}") from error

        if respuesta.status_code >= 400:
            raise LaravelApiError(respuesta.status_code, self._mensaje_error(respuesta))

        return respuesta.json()

    @staticmethod
    def _limpiar(params: dict[str, Any] | None) -> dict[str, Any]:
        """Quita vacíos y convierte booleanos al formato que valida Laravel."""
        limpios: dict[str, Any] = {}
        for clave, valor in (params or {}).items():
            if valor is None or valor == "":
                continue
            limpios[clave] = ("1" if valor else "0") if isinstance(valor, bool) else valor
        return limpios

    @staticmethod
    def _mensaje_error(respuesta: httpx.Response) -> str:
        try:
            cuerpo = respuesta.json()
        except ValueError:
            return f"Error HTTP {respuesta.status_code} de la API de SI-GESTA."

        mensaje = cuerpo.get("message") or f"Error HTTP {respuesta.status_code}."
        if errores := cuerpo.get("errors"):
            detalle = "; ".join(f"{campo}: {', '.join(msgs)}" for campo, msgs in errores.items())
            mensaje = f"{mensaje} ({detalle})"
        return mensaje
