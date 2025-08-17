<?php

class Einstellungen extends IPSModule
{
    public function Create()
    {
        parent::Create();

        ##############################
        // 1. Boolean-Variable für Heizungsstatus
        $this->RegisterVariableBoolean("heating_status", $this->Translate("Heating status"), "", 0);
        $this->EnableAction("heating_status");

        ##############################
        // 2. Boolean-Variable für Urlaubsmodus
        $this->RegisterVariableBoolean("vacation_status", $this->Translate("Vacation mode"), "", 1);
        $this->EnableAction("vacation_status");

        ##############################
        // 3. Property für Frostschutz-Temperatur
        $this->RegisterPropertyFloat("FrostProtection", 5.0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Initial einmal prüfen, ob heating_status schon false ist,
        // dann vacation_status deaktivieren, sonst aktivieren
        $heatingValue = $this->GetValue("heating_status");
        $vacationIdentID = $this->GetIDForIdent("vacation_status");
        if ($heatingValue === false) {
            IPS_SetDisabled($vacationIdentID, true);
        } else {
            IPS_SetDisabled($vacationIdentID, false);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "heating_status":
                // Heizungsstatus ändern
                $this->SetValue("heating_status", $Value);

                // Wenn Heizung aus → Urlaubsmodus-Checkbox deaktivieren
                // Wenn Heizung an → Urlaubsmodus-Checkbox aktivieren
                $vacationIdentID = $this->GetIDForIdent("vacation_status");
                if ($Value === false) {
                    IPS_SetDisabled($vacationIdentID, true);
                    $this->SetValue("vacation_status", false);
                } else {
                    IPS_SetDisabled($vacationIdentID, false);
                }
                break;

            case "vacation_status":
                // Urlaubsmodus nur setzen, wenn Heizungsstatus nicht deaktiviert
                $heatingValue = $this->GetValue("heating_status");
                if ($heatingValue === true) {
                    $this->SetValue("vacation_status", $Value);
                }
                break;
        }
    }
}
?>
