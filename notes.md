# AMAN architecture

## System flow

1. The backend receives Nokia network information and crowd readings for each zone.
2. It calculates crowd density, compares it with configured venue thresholds and creates an incident when risk is detected.
3. It recommends an authorized, available and reachable responder. An operator reviews and approves the response.
4. The backend broadcasts updates to the command-center website and Unity simulation and records the incident until resolution.

Device counts are estimates, not exact people counts. Safety thresholds must be approved for the real venue.

## Website and simulation

Unity exports the interactive simulation as a WebGL build. It can be integrated in either of these ways:

- **Iframe:** host the Unity WebGL build and embed its `index.html` in the website. This is the simplest option.
- **React component:** load the Unity WebGL build through a React integration library. This gives the website more direct control.

In both options, the website receives live backend events and passes them to Unity. The same event contract is used, so the embedding option can be chosen later.

## Data sources

- **Nokia Network as Code:** official simulated Location Retrieval, Location Verification, Device Reachability and Congestion Insights responses.
- **Orange Population Density Data:** official playground with mocked population-density estimates.
- **Crowd-count stream:** controlled simulated readings until a public Region Device Count implementation becomes available.

The backend keeps provider-specific code separate so a simulator can later be replaced with an approved live provider without changing the website or Unity event contract.
