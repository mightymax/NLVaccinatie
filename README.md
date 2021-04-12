# Nederlandse Vaccinatie Tracker

## Wat is de Nederlandse Vaccinatie Tracker?
De Nederlandse Vaccinatie Tracker is een [Twitter account @NLVaccinatie](https://twitter.com/NLVaccinatie) en een bot die een sterk vereenvoudigde grafiek toont van het percentage Nederlanders dat minimaal één vaccinatie heeft gekregen tegen COVID-19. De gegevens worden elke dag rond 16:00 berekend en als Tweet geplaatst op de tijdlijn. Door [@NLVaccinatie](https://twitter.com/NLVaccinatie) te volgen, krijg je dus elke dag een nieuwe grafiek op je eigen tijdlijn.

## Hoe berekent @NLVaccinatie het percentage gevaccineerde inwoners?
Het [Coronadashboard van de Rijskoverheid](https://coronadashboard.rijksoverheid.nl/) geeft geen cijfers over het percentage inwoners dat is ingeënt, maar alleen over het aantal prikken. Er wordt verschil gemaakt tussen het aantal "Berekende" en "Gezette" prikken. Voor meer informatie hierover, zie *[Uitleg bij de cijfers](https://coronadashboard.rijksoverheid.nl/verantwoording#vaccinatie).

Om van het aantal berekende prikken te komen tot een percentage ingeënte inwoners ouder dan 18 wordt we de volgende formule gebruikt (overbodige haakjes voor de leesbaarheid):
**((Prik<sub>1e</sub> / Prik<sub>tot</sub>) * Prik<sub>berekend</sub>) ÷ (Inw<sub>tot</sub> - Inw<sub>o18</sub>)**

Waarbij de gegevens afkomstig zijn van:
- **Prik<sub>1e</sub> &amp; Prik<sub>tot</sub>:** [RIVM](https://www.rivm.nl/covid-19-vaccinatie/cijfers-vaccinatieprogramma)
- **Prik<sub>berekend</sub>:** [Corona Dashboard Rijksoverheid; Berekende Prikken](https://coronadashboard.rijksoverheid.nl/landelijk/vaccinaties)
- **Inw<sub>tot</sub> & Inw<sub>o18</sub>:** [CBS: Bevolkingsteller](https://www.cbs.nl/nl-nl/visualisaties/dashboard-bevolking/bevolkingsteller/)

## Klopt de berekening van @NLVaccinatie eigenlijk wel?
Nee

## @NLVaccinatie is dus eigenlijk fakenews of zelfs een wappie?
Nee dat nou ook weer niet. Probleem is dat de data die in Nederland verzamelt wordt niet geschikt is om exact te berekenen wat het percentage ingeënte Nederlandse inwoners is. @NLVaccinatie doet een vereenvoudigde statistische berekening zoals uit de formule blijkt. Op het Dashboard van de Rijksoverheid wordt gemeld dat de juiste cijfers ooit wel beschikbaar komen, als het zover is zal @NLVaccinatie daar uiteraard gebruik van maken.

## Wat een origineel idee is dat @NLVaccinatie
Nee, helaas niet. Onder het mom *beter goed gejat dan slecht bedacht* moeten de credits gaan naar [@USVaccineCount](https://twitter.com/USVaccineCount)

##Kan ik zelf ook een Twitterbot maken die zoiets doet?
Ja hoor! Doe het als volgt:
- Download de broncode en zorg dat je een Twitter account hebt.
- Installeer [`twurl`](https://github.com/twitter/twurl). 
- Maak een [Twitter App](https://developer.twitter.com/en/docs/apps/overview) aan en zorg dat je de juiste Tokens gebruikt
- Authenticeer: `twurl authorize --consumer-key <jouw-consumer-key> --consumer-secret <jouw-consumer-secret>`
- Run het script als volgt: `php NLVaccinatieTweet.php -tweet`
- Als je `-tweet` weglaat voer je het script uit in *Dry Run* modus en wordt er geen Tweet verstuurd maar kun je wel testen of alles werkt.