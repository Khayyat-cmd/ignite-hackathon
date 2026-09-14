using UnityEngine;

public class OrbitCamera : MonoBehaviour
{
    public Transform target;
    public float distance = 10.0f;
    public float minDistance = 3.0f;
    public float maxDistance = 20.0f;
    public float scrollSpeed = 40.0f;

    private Vector3 offset;
    private float xSpeed = 250.0f;
    private float ySpeed = 125.0f;
    private float yMinLimit = -20.0f;
    private float yMaxLimit = 80.0f;

    private float x = 0.0f;
    private float y = 0.0f;

    void Start()
    {
        Vector3 angles = transform.eulerAngles;
        x = angles.y;
        y = angles.x;

        if (GetComponent<Rigidbody>() != null)
            GetComponent<Rigidbody>().freezeRotation = true;
    }

    void LateUpdate()
    {
        if (target == null) return;

        if (Input.GetMouseButton(0))
        {
            x += Input.GetAxis("Mouse X") * xSpeed * Time.deltaTime;
            y -= Input.GetAxis("Mouse Y") * ySpeed * Time.deltaTime;
        }

        y = ClampAngle(y, yMinLimit, yMaxLimit);

        distance -= Input.GetAxis("Mouse ScrollWheel") * scrollSpeed;

        distance = Mathf.Clamp(distance, minDistance, maxDistance);

        Quaternion rotation = Quaternion.Euler(y, x, 0);
        Vector3 position = rotation * new Vector3(0.0f, 0.0f, -distance) + target.position;

        transform.rotation = rotation;
        transform.position = position;
    }

    float ClampAngle(float angle, float min, float max)
    {
        if (angle < -360)
            angle += 360;
        else if (angle > 360)
            angle -= 360;

        return Mathf.Clamp(angle, min, max);
    }
}
