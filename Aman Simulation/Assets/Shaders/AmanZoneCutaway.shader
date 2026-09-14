Shader "AMAN/ZoneCutawayLit"
{
    Properties
    {
        [MainTexture] _BaseMap("Base Map", 2D) = "white" {}
        [MainColor] _BaseColor("Base Color", Color) = (1, 1, 1, 1)
        _Cutoff("Alpha Cutoff", Range(0, 1)) = 0.5
        _AlphaClip("Alpha Clipping", Float) = 0
        _Cull("Cull", Float) = 2
    }

    SubShader
    {
        Tags { "RenderType"="Opaque" "Queue"="Geometry" "RenderPipeline"="UniversalPipeline" }

        HLSLINCLUDE
        #include "Packages/com.unity.render-pipelines.universal/ShaderLibrary/Core.hlsl"

        TEXTURE2D(_BaseMap);
        SAMPLER(sampler_BaseMap);

        CBUFFER_START(UnityPerMaterial)
            float4 _BaseMap_ST;
            half4 _BaseColor;
            half _Cutoff;
            half _AlphaClip;
        CBUFFER_END

        float _AmanCutawayEnabled;
        float3 _AmanCutawayCamera;
        float3 _AmanCutawayTarget;
        float _AmanCutawayNearRadius;
        float _AmanCutawayTargetRadius;
        float _AmanCutawayNearPadding;
        float _AmanCutawayTargetPadding;
        float _AmanCutawayFloorNormalThreshold;

        struct Attributes
        {
            float4 positionOS : POSITION;
            float3 normalOS : NORMAL;
            float2 uv : TEXCOORD0;
            UNITY_VERTEX_INPUT_INSTANCE_ID
        };

        struct Varyings
        {
            float4 positionCS : SV_POSITION;
            float3 positionWS : TEXCOORD0;
            half3 normalWS : TEXCOORD1;
            float2 uv : TEXCOORD2;
            half fogFactor : TEXCOORD3;
            UNITY_VERTEX_INPUT_INSTANCE_ID
            UNITY_VERTEX_OUTPUT_STEREO
        };

        Varyings CutawayVertex(Attributes input)
        {
            Varyings output = (Varyings)0;
            UNITY_SETUP_INSTANCE_ID(input);
            UNITY_TRANSFER_INSTANCE_ID(input, output);
            UNITY_INITIALIZE_VERTEX_OUTPUT_STEREO(output);
            VertexPositionInputs positionInputs = GetVertexPositionInputs(input.positionOS.xyz);
            VertexNormalInputs normalInputs = GetVertexNormalInputs(input.normalOS);
            output.positionCS = positionInputs.positionCS;
            output.positionWS = positionInputs.positionWS;
            output.normalWS = normalInputs.normalWS;
            output.uv = TRANSFORM_TEX(input.uv, _BaseMap);
            output.fogFactor = ComputeFogFactor(positionInputs.positionCS.z);
            return output;
        }

        void ApplyAmanCutaway(float3 positionWS, half3 normalWS)
        {
            if (_AmanCutawayEnabled < 0.5) return;
            if (dot(normalize(normalWS), float3(0.0, 1.0, 0.0)) > _AmanCutawayFloorNormalThreshold) return;

            float3 segment = _AmanCutawayTarget - _AmanCutawayCamera;
            float segmentLength = max(length(segment), 0.001);
            float3 direction = segment / segmentLength;
            float along = dot(positionWS - _AmanCutawayCamera, direction);
            if (along <= _AmanCutawayNearPadding || along >= segmentLength - _AmanCutawayTargetPadding) return;

            float progress = saturate(along / segmentLength);
            float radius = lerp(_AmanCutawayNearRadius, _AmanCutawayTargetRadius,
                progress * progress * (3.0 - 2.0 * progress));
            float3 closestPoint = _AmanCutawayCamera + direction * along;
            if (distance(positionWS, closestPoint) < radius) clip(-1);
        }

        half4 SampleBase(float2 uv)
        {
            half4 color = SAMPLE_TEXTURE2D(_BaseMap, sampler_BaseMap, uv) * _BaseColor;
            if (_AlphaClip > 0.5) clip(color.a - _Cutoff);
            return color;
        }
        ENDHLSL

        Pass
        {
            Name "ForwardLit"
            Tags { "LightMode"="UniversalForward" }
            Cull [_Cull]
            ZWrite On

            HLSLPROGRAM
            #pragma target 3.0
            #pragma vertex CutawayVertex
            #pragma fragment ForwardFragment
            #pragma multi_compile_fog
            #pragma multi_compile _ _MAIN_LIGHT_SHADOWS _MAIN_LIGHT_SHADOWS_CASCADE _MAIN_LIGHT_SHADOWS_SCREEN
            #pragma multi_compile_instancing
            #include "Packages/com.unity.render-pipelines.universal/ShaderLibrary/Lighting.hlsl"

            half4 ForwardFragment(Varyings input) : SV_Target
            {
                UNITY_SETUP_INSTANCE_ID(input);
                ApplyAmanCutaway(input.positionWS, input.normalWS);
                half4 baseColor = SampleBase(input.uv);
                half3 normalWS = normalize(input.normalWS);
                float4 shadowCoord = TransformWorldToShadowCoord(input.positionWS);
                Light mainLight = GetMainLight(shadowCoord);
                half3 direct = LightingLambert(mainLight.color * (mainLight.distanceAttenuation * mainLight.shadowAttenuation),
                    mainLight.direction, normalWS);
                half3 ambient = SampleSH(normalWS);
                baseColor.rgb = MixFog(baseColor.rgb * (ambient + direct), input.fogFactor);
                return baseColor;
            }
            ENDHLSL
        }

        Pass
        {
            Name "DepthOnly"
            Tags { "LightMode"="DepthOnly" }
            Cull [_Cull]
            ZWrite On
            ColorMask 0

            HLSLPROGRAM
            #pragma target 3.0
            #pragma vertex CutawayVertex
            #pragma fragment DepthFragment
            #pragma multi_compile_instancing

            half4 DepthFragment(Varyings input) : SV_Target
            {
                UNITY_SETUP_INSTANCE_ID(input);
                ApplyAmanCutaway(input.positionWS, input.normalWS);
                SampleBase(input.uv);
                return 0;
            }
            ENDHLSL
        }

        Pass
        {
            Name "ShadowCaster"
            Tags { "LightMode"="ShadowCaster" }
            Cull [_Cull]
            ZWrite On
            ColorMask 0

            HLSLPROGRAM
            #pragma target 3.0
            #pragma vertex CutawayVertex
            #pragma fragment ShadowFragment
            #pragma multi_compile_instancing

            half4 ShadowFragment(Varyings input) : SV_Target
            {
                UNITY_SETUP_INSTANCE_ID(input);
                ApplyAmanCutaway(input.positionWS, input.normalWS);
                SampleBase(input.uv);
                return 0;
            }
            ENDHLSL
        }
    }

    FallBack Off
}
