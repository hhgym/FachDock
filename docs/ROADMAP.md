# FachDock – mögliche Weiterentwicklungen

Diese Liste sammelt bewusst noch nicht für die aktuelle Version eingeplante Erweiterungen.

## Identität und Anmeldung

- **Konfigurierbare Anmeldewege je Zielgruppe**: Die verfügbaren Loginverfahren sollen perspektivisch getrennt für Eltern, Schüler, Lehrkräfte sowie Administration/Schließfachverwaltung konfiguriert, aktiviert/deaktiviert und in ihrer Darstellung priorisiert werden können. Denkbare Verfahren sind Magic Link, OpenID Connect und lokale Anmeldung. Damit soll insbesondere möglich werden, Eltern und Schüler später beide über denselben OpenID-Connect-Provider (z. B. IServ) anzumelden, ohne dass die Loginseite oder Rollenlogik auf einen bestimmten Anbieter fest verdrahtet ist. Ein deaktivierter Anmeldeweg darf auf der Loginseite nicht mehr angeboten werden; mindestens ein funktionsfähiger administrativer Zugang muss als Sicherheitsnetz erhalten bleiben.
- **OpenID-Connect-Anmeldung für Eltern**: Eltern sollen perspektivisch ebenfalls über einen externen OpenID-Connect-Provider wie IServ angemeldet und anhand konfigurierbarer Rollen und Claims ihren FachDock-Elternkontakten zugeordnet werden können. Für die aktuelle Version bleibt die Elternanmeldung unverändert beim bestehenden Magic-Link-Verfahren. Vor einer Umsetzung sind insbesondere die vom Provider verfügbaren Eltern-Kind-Zuordnungsdaten, Rollen/Claims, Datenschutzanforderungen und ein belastbarer Fallback zu klären.
