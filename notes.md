Unity Simulation Integration:
    -THE SIMPLE WAY:We export the Unity simulation as a WebGL build and embed it in the React/Next.js website using iframe. Users can interact with it directly in the browser.
    -THE LESS SIMPLE WAY:We export the Unity simulation as a WebGL build and load its build files directly inside a React component using a library such as react-unity-webgl, which gives more control for user.

Backend:
    -Process Information and Make Decisions: The backend receives information from CAMARA APIs,sends them to simulation and frontend, evaluates crowd risk, selects a suitable reachable responder, and manages the incident from detection to resolution.
    -Connect and Update the System: The backend connects the web command center, Unity simulation, and responder interface, sends live updates to each one, and records all decisions and actions
